<?php

namespace App\Classes\Subscriptions;

use App\Classes\PaymentService\PaymentService;
use App\Classes\PaymentService\PaymentSourceChargeRequest;
use App\Enums\PaymentCurrency;
use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentProvider;
use App\Models\PaymentOrder;
use App\Models\PaymentSource;
use App\Models\Subscription;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SubscriptionBillingService
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly SubscriptionPlanCatalog $planCatalog,
        private readonly SubscriptionPlanActivator $subscriptionPlanActivator
    ) {}

    /**
     * @return array{processed:int,approved:int,pending:int,failed:int,skipped:int}
     */
    public function billDueRecurringSubscriptions(?Carbon $now = null): array
    {
        $now ??= now();
        $summary = [
            'processed' => 0,
            'approved' => 0,
            'pending' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        Subscription::query()
            ->where('active', true)
            ->where('billing_mode', 'recurring')
            ->where('cancel_at_period_end', false)
            ->where(function ($query) use ($now): void {
                $query
                    ->where('next_billing_at', '<=', $now)
                    ->orWhere(function ($query) use ($now): void {
                        $query
                            ->whereNull('next_billing_at')
                            ->where('renews_at', '<=', $now);
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use (&$summary, $now): void {
                foreach ($subscriptions as $subscription) {
                    if (! $subscription instanceof Subscription) {
                        continue;
                    }

                    $result = $this->billSubscription($subscription, $now);
                    $summary['processed']++;
                    $summary[$result]++;
                }
            });

        return $summary;
    }

    private function billSubscription(Subscription $subscription, Carbon $now): string
    {
        [$paymentOrder, $created] = DB::transaction(function () use ($subscription, $now): array {
            /** @var Subscription|null $lockedSubscription */
            $lockedSubscription = Subscription::query()
                ->with('user', 'paymentSource')
                ->whereKey($subscription->id)
                ->lockForUpdate()
                ->first();

            if (! $lockedSubscription instanceof Subscription || ! $this->isDueForBilling($lockedSubscription, $now)) {
                return [null, false];
            }

            if (! $this->planCatalog->isPurchasable($lockedSubscription->plan)) {
                return [null, false];
            }

            $paymentSource = $lockedSubscription->paymentSource;

            if (! $paymentSource instanceof PaymentSource || ! $this->canChargePaymentSource($paymentSource)) {
                return [null, false];
            }

            $pendingOrder = $this->pendingRenewalOrderFor($lockedSubscription);

            if ($pendingOrder instanceof PaymentOrder) {
                return [$pendingOrder, false];
            }

            return [
                $this->createRenewalOrder($lockedSubscription, $paymentSource),
                true,
            ];
        });

        if (! $paymentOrder instanceof PaymentOrder || ! $created) {
            return 'skipped';
        }

        $charge = $this->paymentService->chargePaymentSource(new PaymentSourceChargeRequest(
            reference: $paymentOrder->reference,
            amountInCents: $paymentOrder->amount_in_cents,
            currency: $paymentOrder->currency->value,
            customerEmail: $paymentOrder->user->email,
            paymentSourceProviderId: (string) $paymentOrder->paymentSource->provider_source_id,
            installments: 1,
            recurrent: true,
        ));

        $paymentOrder->provider_transaction_id = $charge->providerTransactionId;
        $paymentOrder->wompi_status = $charge->providerStatus;
        $paymentOrder->raw_provider_payload = $charge->toArray();
        $paymentOrder->status = PaymentOrderStatus::from($charge->status);

        if ($paymentOrder->status === PaymentOrderStatus::Approved) {
            $paymentOrder->paid_at = now();
        }

        $paymentOrder->save();

        if ($paymentOrder->status === PaymentOrderStatus::Approved) {
            $this->subscriptionPlanActivator->activateForPaymentOrder($paymentOrder);
            $this->dispatchRenewalNotifications($paymentOrder);

            return 'approved';
        }

        if ($paymentOrder->status === PaymentOrderStatus::Pending) {
            $this->dispatchRenewalNotifications($paymentOrder);

            return 'pending';
        }

        $this->dispatchRenewalNotifications($paymentOrder);

        return 'failed';
    }

    private function isDueForBilling(Subscription $subscription, Carbon $now): bool
    {
        $dueAt = $subscription->next_billing_at ?? $subscription->renews_at;

        return $subscription->active
            && $subscription->billing_mode === 'recurring'
            && ! $subscription->cancel_at_period_end
            && $dueAt->lessThanOrEqualTo($now);
    }

    private function canChargePaymentSource(PaymentSource $paymentSource): bool
    {
        return $paymentSource->provider === PaymentProvider::Wompi
            && $paymentSource->reusable
            && $paymentSource->status === 'active'
            && filled($paymentSource->provider_source_id);
    }

    private function pendingRenewalOrderFor(Subscription $subscription): ?PaymentOrder
    {
        return PaymentOrder::query()
            ->where('user_id', $subscription->user_id)
            ->where('payment_source_id', $subscription->payment_source_id)
            ->where('plan', $subscription->plan)
            ->where('recurring', true)
            ->where('billing_reason', 'subscription_renewal')
            ->where('status', PaymentOrderStatus::Pending)
            ->where('created_at', '>=', $subscription->renews_at)
            ->orderByDesc('id')
            ->first();
    }

    private function createRenewalOrder(Subscription $subscription, PaymentSource $paymentSource): PaymentOrder
    {
        $amounts = $this->amountsForPlan($subscription);

        return PaymentOrder::create([
            'user_id' => $subscription->user_id,
            'payment_source_id' => $paymentSource->id,
            'provider' => PaymentProvider::Wompi,
            'reference' => $this->uniqueReference($subscription->user_id),
            'plan' => $subscription->plan,
            'recurring' => true,
            'billing_reason' => 'subscription_renewal',
            'display_amount_usd' => $amounts['display_amount_usd'],
            'display_currency' => PaymentCurrency::Usd,
            'exchange_rate' => $amounts['exchange_rate'],
            'amount_cop' => $amounts['amount_cop'],
            'amount_in_cents' => $amounts['amount_in_cents'],
            'currency' => PaymentCurrency::Cop,
            'status' => PaymentOrderStatus::Pending,
        ]);
    }

    /**
     * @return array{display_amount_usd:float,exchange_rate:float,amount_cop:float,amount_in_cents:int}
     */
    private function amountsForPlan(Subscription $subscription): array
    {
        $planConfig = $this->planCatalog->configFor($subscription->plan);
        $displayAmountUsd = round((float) ($planConfig['price_usd'] ?? 0), 2);
        $exchangeRate = (float) config('payment.usd_cop_rate', 4000);
        $amountInCents = (int) round($displayAmountUsd * $exchangeRate * 100);

        return [
            'display_amount_usd' => $displayAmountUsd,
            'exchange_rate' => $exchangeRate,
            'amount_cop' => round($amountInCents / 100, 2),
            'amount_in_cents' => $amountInCents,
        ];
    }

    private function uniqueReference(int $userId): string
    {
        do {
            $reference = 'VOI-REN-'.$userId.'-'.Str::upper(Str::random(12));
        } while (PaymentOrder::where('reference', $reference)->exists());

        return $reference;
    }

    private function dispatchRenewalNotifications(PaymentOrder $paymentOrder): void
    {
        $paymentOrder->loadMissing('user');
        $user = $paymentOrder->user;

        if (! $user) {
            return;
        }

        $dispatcher = app(NotificationDispatcher::class);
        $data = $this->notificationDataForOrder($paymentOrder);

        if ($paymentOrder->status === PaymentOrderStatus::Approved) {
            $dispatcher->send($user, 'payment_approved', $data);
            $dispatcher->send($user, 'successful_subscription_renewal', $data);
            $dispatcher->send($user, 'plan_activated_or_changed', $data);

            return;
        }

        if ($paymentOrder->status === PaymentOrderStatus::Pending) {
            $dispatcher->sendInApp($user, 'payment_pending', $data);

            return;
        }

        $dispatcher->send($user, 'payment_rejected', $data);
        $dispatcher->send($user, 'failed_payment', $data);
        $dispatcher->send($user, 'failed_subscription_renewal', $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationDataForOrder(PaymentOrder $paymentOrder): array
    {
        return [
            'plan' => $paymentOrder->plan->value,
            'amount' => sprintf('USD %.2f', (float) $paymentOrder->display_amount_usd),
            'payment_order_id' => $paymentOrder->id,
            'reference' => $paymentOrder->reference,
        ];
    }
}
