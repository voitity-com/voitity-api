<?php

namespace App\Classes\Subscriptions;

use App\Classes\PaymentService\PaymentPayloadSanitizer;
use App\Classes\PaymentService\PaymentService;
use App\Classes\PaymentService\PaymentSourceCharge;
use App\Classes\PaymentService\PaymentSourceChargeRequest;
use App\Classes\PaymentService\PaymentSourceCreateRequest;
use App\Enums\PaymentCurrency;
use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\SubscriptionPlan;
use App\Models\PaymentOrder;
use App\Models\PaymentSource;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class SubscriptionPaymentSourceService
{
    public function __construct(
        private readonly SubscriptionPlanCatalog $planCatalog,
        private readonly SubscriptionPlanActivator $subscriptionPlanActivator,
        private readonly PaymentMethodService $paymentMethods,
        private readonly PaymentPayloadSanitizer $payloadSanitizer,
    ) {}

    /**
     * @return array{subscription:?Subscription,payment_source:PaymentSource,payment_order:PaymentOrder,charge:PaymentSourceCharge}
     */
    public function startSubscriptionWithPaymentSource(
        User $user,
        SubscriptionPlan $plan,
        PaymentService $paymentService,
        PaymentSourceCreateRequest $paymentSourceRequest,
        CustomerTermsAcceptance $termsAcceptance,
    ): array {
        $this->ensureSubscriptionCanStart($user, $plan);
        $paymentSource = $this->paymentMethods->create($user, $paymentSourceRequest);

        return $this->startSubscriptionWithExistingPaymentSource(
            $user,
            $plan,
            $paymentService,
            $paymentSource,
            $termsAcceptance,
        );
    }

    /**
     * @return array{subscription:?Subscription,payment_source:PaymentSource,payment_order:PaymentOrder,charge:PaymentSourceCharge}
     */
    public function startSubscriptionWithExistingPaymentSource(
        User $user,
        SubscriptionPlan $plan,
        PaymentService $paymentService,
        PaymentSource $paymentSource,
        CustomerTermsAcceptance $termsAcceptance,
    ): array {
        $this->ensureSubscriptionCanStart($user, $plan);

        if ((int) $paymentSource->user_id !== (int) $user->id || ! $paymentSource->isChargeable()) {
            throw new RuntimeException('The selected payment method is not available for recurring charges.');
        }

        $paymentOrder = DB::transaction(function () use ($user, $plan, $paymentSource, $termsAcceptance): PaymentOrder {
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->ensureSubscriptionCanStart($lockedUser, $plan);

            return $this->createInitialOrder($lockedUser, $plan, $paymentSource, $termsAcceptance);
        });

        $charge = $paymentService->chargePaymentSource(new PaymentSourceChargeRequest(
            reference: $paymentOrder->reference,
            amountInCents: $paymentOrder->amount_in_cents,
            currency: $paymentOrder->currency->value,
            customerEmail: $paymentOrder->user->email,
            paymentSourceProviderId: (string) $paymentSource->provider_source_id,
            installments: 1,
            recurrent: true,
        ));

        if ($charge->isPending() && $charge->providerTransactionId) {
            $charge = $this->resolvePendingCharge($paymentService, $paymentOrder, $charge);
        }

        $chargedAt = now();

        $paymentOrder->provider_transaction_id = $charge->providerTransactionId;
        $paymentOrder->wompi_status = $charge->providerStatus;
        $paymentOrder->raw_provider_payload = $this->payloadSanitizer->paymentResult($charge->toArray());
        $paymentOrder->status = PaymentOrderStatus::from($charge->status);

        if ($paymentOrder->status === PaymentOrderStatus::Approved) {
            $paymentOrder->paid_at = $chargedAt;
        }

        $paymentOrder->save();
        $paymentSource->forceFill(['last_used_at' => $chargedAt])->save();

        if ($paymentOrder->status === PaymentOrderStatus::Declined) {
            $this->paymentMethods->markRejectedAfterDeclinedPayment($paymentOrder);
        } elseif ($paymentOrder->status === PaymentOrderStatus::Approved) {
            $this->paymentMethods->clearFailureAfterApprovedPayment($paymentOrder);
        }

        $subscription = null;

        if ($paymentOrder->status === PaymentOrderStatus::Approved) {
            $subscription = $this->subscriptionPlanActivator->activateForPaymentOrder($paymentOrder);
            $paymentSource->refresh();
        }

        $this->dispatchPaymentNotification($paymentOrder->fresh(['user']));

        Log::info('Initial subscription payment processed.', [
            'payment_order_id' => $paymentOrder->id,
            'payment_source_id' => $paymentSource->id,
            'plan' => $plan->value,
            'status' => $paymentOrder->status->value,
            'user_id' => $user->id,
        ]);

        return [
            'subscription' => $subscription,
            'payment_source' => $paymentSource->fresh(),
            'payment_order' => $paymentOrder->fresh(),
            'charge' => $charge,
        ];
    }

    private function ensureSubscriptionCanStart(User $user, SubscriptionPlan $plan): void
    {
        $planConfig = $this->planCatalog->configFor($plan);
        $priceUsd = $planConfig['price_usd'] ?? null;

        if (! $this->planCatalog->isPurchasable($plan) || ! is_numeric($priceUsd) || (float) $priceUsd <= 0) {
            throw new RuntimeException('Selected plan is not available for checkout.');
        }

        if ($user->subscriptions()->where('active', true)->exists()) {
            throw new RuntimeException('An active subscription already exists.');
        }
    }

    private function createInitialOrder(
        User $user,
        SubscriptionPlan $plan,
        PaymentSource $paymentSource,
        CustomerTermsAcceptance $termsAcceptance,
    ): PaymentOrder {
        $amounts = $this->amountsForPlan($plan);

        /** @var PaymentOrder $paymentOrder */
        $paymentOrder = PaymentOrder::query()->create([
            'user_id' => $user->id,
            'payment_source_id' => $paymentSource->id,
            'provider' => PaymentProvider::Wompi,
            'reference' => $this->uniqueReference($user->id),
            'plan' => $plan,
            'recurring' => true,
            'billing_reason' => 'subscription_initial',
            ...$termsAcceptance->paymentOrderAttributes($plan, $this->planCatalog),
            'display_amount_usd' => $amounts['display_amount_usd'],
            'display_currency' => PaymentCurrency::Usd,
            'exchange_rate' => $amounts['exchange_rate'],
            'amount_cop' => $amounts['amount_cop'],
            'amount_in_cents' => $amounts['amount_in_cents'],
            'currency' => PaymentCurrency::Cop,
            'status' => PaymentOrderStatus::Pending,
        ]);

        return $paymentOrder;
    }

    private function resolvePendingCharge(
        PaymentService $paymentService,
        PaymentOrder $paymentOrder,
        PaymentSourceCharge $charge,
    ): PaymentSourceCharge {
        $attempts = max(0, (int) config('payment.pending_charge_poll_attempts', 3));
        $delayMs = max(0, (int) config('payment.pending_charge_poll_delay_ms', 500));

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $refreshedCharge = $paymentService->getPaymentSourceCharge(
                providerTransactionId: (string) $charge->providerTransactionId,
                reference: $paymentOrder->reference,
                amountInCents: $paymentOrder->amount_in_cents,
                currency: $paymentOrder->currency->value,
            );

            if (! $refreshedCharge->isPending()) {
                return $refreshedCharge;
            }

            $charge = $refreshedCharge;
        }

        return $charge;
    }

    /**
     * @return array{display_amount_usd:float,exchange_rate:float,amount_cop:float,amount_in_cents:int}
     */
    private function amountsForPlan(SubscriptionPlan $plan): array
    {
        $planConfig = $this->planCatalog->configFor($plan);
        $displayAmountUsd = round((float) ($planConfig['price_usd'] ?? 0), 2);
        $exchangeRate = (float) config('payment.usd_cop_rate', 4000);

        if ($exchangeRate <= 0) {
            throw new RuntimeException('Invalid USD to COP exchange rate configuration.');
        }

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
            $reference = 'VOI-SUB-'.$userId.'-'.Str::upper(Str::random(12));
        } while (PaymentOrder::where('reference', $reference)->exists());

        return $reference;
    }

    private function dispatchPaymentNotification(?PaymentOrder $paymentOrder): void
    {
        if (! $paymentOrder instanceof PaymentOrder || ! $paymentOrder->user) {
            return;
        }

        $dispatcher = app(NotificationDispatcher::class);
        $data = [
            'plan' => $paymentOrder->plan->value,
            'amount' => sprintf('USD %.2f', (float) $paymentOrder->display_amount_usd),
            'payment_order_id' => $paymentOrder->id,
            'reference' => $paymentOrder->reference,
        ];

        if ($paymentOrder->status === PaymentOrderStatus::Approved) {
            $dispatcher->send($paymentOrder->user, 'successful_plan_purchase', $data);

            return;
        }

        if ($paymentOrder->status === PaymentOrderStatus::Pending) {
            $dispatcher->sendInApp($paymentOrder->user, 'payment_pending', $data);

            return;
        }

        $dispatcher->send($paymentOrder->user, 'failed_payment', $data);
    }
}
