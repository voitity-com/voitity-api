<?php

namespace App\Classes\Subscriptions;

use App\Classes\PaymentService\PaymentIntent;
use App\Classes\PaymentService\PaymentRequest;
use App\Classes\PaymentService\PaymentService;
use App\Enums\PaymentCurrency;
use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SubscriptionTrialService
{
    public function __construct(
        private readonly SubscriptionPlanCatalog $planCatalog,
        private readonly SubscriptionLimitPeriodService $limitPeriods,
    ) {}

    /**
     * @return array{payment_order:PaymentOrder,intent:PaymentIntent}
     */
    public function startTrialCheckout(User $user, SubscriptionPlan $plan, PaymentService $paymentService): array
    {
        $this->ensureTrialCanStart($user, $plan);

        $exchangeRate = (float) config('payment.usd_cop_rate', 4000);

        if ($exchangeRate <= 0) {
            throw new RuntimeException('Invalid USD to COP exchange rate configuration.');
        }

        $displayAmountUsd = max(0, round((float) config('subscriptions.trial.setup_amount_usd', 0), 2));
        $amountInCents = (int) round($displayAmountUsd * $exchangeRate * 100);
        $amountCop = round($amountInCents / 100, 2);
        $expiresAt = now()->addMinutes(max(1, (int) config('payment.checkout_expires_in_minutes', 60)));

        /** @var PaymentOrder $paymentOrder */
        $paymentOrder = PaymentOrder::create([
            'user_id' => $user->id,
            'provider' => PaymentProvider::Wompi,
            'reference' => $this->uniqueReference($user->id),
            'plan' => $plan,
            'recurring' => true,
            'billing_reason' => 'trial_setup',
            'display_amount_usd' => $displayAmountUsd,
            'display_currency' => PaymentCurrency::Usd,
            'exchange_rate' => $exchangeRate,
            'amount_cop' => $amountCop,
            'amount_in_cents' => $amountInCents,
            'currency' => PaymentCurrency::Cop,
            'status' => PaymentOrderStatus::Pending,
            'expires_at' => $expiresAt,
        ]);

        $intent = $paymentService->createPayment(new PaymentRequest(
            reference: $paymentOrder->reference,
            amountInCents: $paymentOrder->amount_in_cents,
            currency: $paymentOrder->currency->value,
            redirectUrl: $this->redirectUrlFor($paymentOrder),
            expirationTime: $expiresAt,
            customerData: $this->customerDataFor($user),
        ));

        $paymentOrder->checkout_url = $intent->checkoutUrl;
        $paymentOrder->raw_provider_payload = $intent->toArray();
        $paymentOrder->save();

        app(NotificationDispatcher::class)->sendInApp($user, 'payment_pending', $this->notificationDataForOrder($paymentOrder));

        return [
            'payment_order' => $paymentOrder,
            'intent' => $intent,
        ];
    }

    public function activateTrialFromPaymentOrder(PaymentOrder $paymentOrder): Subscription
    {
        return DB::transaction(function () use ($paymentOrder): Subscription {
            /** @var PaymentOrder $order */
            $order = PaymentOrder::query()
                ->with('user')
                ->whereKey($paymentOrder->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->subscription_id) {
                return $order->subscription()->firstOrFail();
            }

            if ($order->status !== PaymentOrderStatus::Approved) {
                throw new RuntimeException('Only approved payment orders can start trials.');
            }

            if ($order->billing_reason !== 'trial_setup') {
                throw new RuntimeException('Payment order is not a trial setup order.');
            }

            if ($this->requiresPaymentSource() && ! $order->payment_source_id) {
                throw new RuntimeException('A reusable payment source is required to start the trial.');
            }

            $user = $order->user()->lockForUpdate()->firstOrFail();
            $this->ensureTrialCanStart($user, $order->plan);

            $startedAt = now();
            $trialEndsAt = $startedAt->copy()->addDays($this->trialDays());

            /** @var Subscription $subscription */
            $subscription = $user->subscriptions()->create([
                'payment_source_id' => $order->payment_source_id,
                'source_payment_order_id' => $order->id,
                'plan' => $order->plan,
                'billing_mode' => 'recurring',
                'started_at' => $startedAt,
                'trial_started_at' => $startedAt,
                'trial_ends_at' => $trialEndsAt,
                'renews_at' => $trialEndsAt,
                'status' => SubscriptionStatus::Trialing,
                'active' => true,
                'cancel_at_period_end' => false,
                'last_billed_at' => null,
                'next_billing_at' => $trialEndsAt,
            ]);

            $this->limitPeriods->createInitialLimit($subscription);

            $user->free_trial_used_at = $startedAt;
            $user->save();

            $order->subscription_id = $subscription->id;
            $order->save();

            return $subscription;
        });
    }

    public function cancelTrial(User $user): Subscription
    {
        return DB::transaction(function () use ($user): Subscription {
            /** @var Subscription|null $subscription */
            $subscription = $user->subscriptions()
                ->where('active', true)
                ->where('status', SubscriptionStatus::Trialing->value)
                ->where('renews_at', '>', now())
                ->lockForUpdate()
                ->latest('started_at')
                ->first();

            if (! $subscription instanceof Subscription) {
                throw new RuntimeException('Active trial subscription not found.');
            }

            if (! $subscription->cancel_at_period_end) {
                $subscription->cancel_at_period_end = true;
                $subscription->cancelled_at = now();
                $subscription->trial_cancelled_at = now();
                $subscription->save();

                $this->dispatchSubscriptionNotification($user, 'trial_cancelled', $subscription);
            }

            return $subscription->fresh();
        });
    }

    public function cancelRenewal(User $user): Subscription
    {
        return DB::transaction(function () use ($user): Subscription {
            /** @var Subscription|null $subscription */
            $subscription = $user->subscriptions()
                ->where('active', true)
                ->where('billing_mode', 'recurring')
                ->where('status', '!=', SubscriptionStatus::Trialing->value)
                ->where('renews_at', '>', now())
                ->lockForUpdate()
                ->latest('started_at')
                ->first();

            if (! $subscription instanceof Subscription) {
                throw new RuntimeException('Active paid subscription not found.');
            }

            if (! $subscription->cancel_at_period_end) {
                $subscription->cancel_at_period_end = true;
                $subscription->cancelled_at = now();
                $subscription->save();

                $this->dispatchSubscriptionNotification($user, 'subscription_renewal_cancelled', $subscription);
            }

            return $subscription->fresh();
        });
    }

    public function reactivateRenewal(User $user): Subscription
    {
        return DB::transaction(function () use ($user): Subscription {
            /** @var Subscription|null $subscription */
            $subscription = $user->subscriptions()
                ->where('active', true)
                ->where('billing_mode', 'recurring')
                ->where('cancel_at_period_end', true)
                ->where('renews_at', '>', now())
                ->lockForUpdate()
                ->latest('started_at')
                ->first();

            if (! $subscription instanceof Subscription) {
                throw new RuntimeException('Cancelled recurring subscription not found.');
            }

            $subscription->cancel_at_period_end = false;
            $subscription->cancelled_at = null;

            if ($subscription->status === SubscriptionStatus::Trialing) {
                $subscription->trial_cancelled_at = null;
            }

            $subscription->save();

            $this->dispatchSubscriptionNotification($user, 'subscription_renewal_reactivated', $subscription);

            return $subscription->fresh();
        });
    }

    public function expireEndedSubscriptions(?Carbon $now = null): int
    {
        $now ??= now();
        $expired = 0;

        Subscription::query()
            ->where('active', true)
            ->where('cancel_at_period_end', true)
            ->where('renews_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use (&$expired): void {
                foreach ($subscriptions as $subscription) {
                    if (! $subscription instanceof Subscription) {
                        continue;
                    }

                    DB::transaction(function () use ($subscription, &$expired): void {
                        /** @var Subscription|null $lockedSubscription */
                        $lockedSubscription = Subscription::query()
                            ->with('user')
                            ->whereKey($subscription->id)
                            ->lockForUpdate()
                            ->first();

                        if (! $lockedSubscription instanceof Subscription || ! $lockedSubscription->active) {
                            return;
                        }

                        $lockedSubscription->active = false;
                        $lockedSubscription->status = SubscriptionStatus::Cancelled;
                        $lockedSubscription->save();

                        if ($lockedSubscription->user instanceof User) {
                            $this->dispatchSubscriptionNotification(
                                $lockedSubscription->user,
                                'subscription_expired',
                                $lockedSubscription,
                            );
                        }

                        $expired++;
                    });
                }
            });

        return $expired;
    }

    public function userCanStartTrial(User $user): bool
    {
        return $this->trialEnabled()
            && $user->free_trial_used_at === null
            && ! $user->subscriptions()->exists();
    }

    public function trialDays(): int
    {
        return max(1, (int) config('subscriptions.trial.days', 7));
    }

    private function ensureTrialCanStart(User $user, SubscriptionPlan $plan): void
    {
        if (! $this->trialEnabled()) {
            throw new RuntimeException('Free trial is not available.');
        }

        if (! $this->planCatalog->isPurchasable($plan)) {
            throw new RuntimeException('Selected plan is not available for trial.');
        }

        if ($user->free_trial_used_at !== null) {
            throw new RuntimeException('Free trial was already used for this account.');
        }

        if ($user->subscriptions()->exists()) {
            throw new RuntimeException('Free trial is only available before the first subscription.');
        }
    }

    private function trialEnabled(): bool
    {
        return (bool) config('subscriptions.trial.enabled', true);
    }

    private function requiresPaymentSource(): bool
    {
        return (bool) config('subscriptions.trial.requires_payment_source', true);
    }

    private function uniqueReference(int $userId): string
    {
        do {
            $reference = 'VOI-TRI-'.$userId.'-'.Str::upper(Str::random(12));
        } while (PaymentOrder::where('reference', $reference)->exists());

        return $reference;
    }

    private function redirectUrlFor(PaymentOrder $paymentOrder): ?string
    {
        $redirectUrl = config('payment.redirect_url');

        if (! is_string($redirectUrl) || trim($redirectUrl) === '') {
            return null;
        }

        $redirectUrl = trim($redirectUrl);
        $separator = str_contains($redirectUrl, '?') ? '&' : '?';

        return $redirectUrl.$separator.http_build_query([
            'payment_order_id' => $paymentOrder->id,
        ]);
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

    /**
     * @return array<string, string>
     */
    private function customerDataFor(User $user): array
    {
        $data = [
            'email' => $user->email,
            'full-name' => $user->name,
        ];

        return array_filter($data, fn (?string $value): bool => filled($value));
    }

    private function dispatchSubscriptionNotification(User $user, string $type, Subscription $subscription): void
    {
        app(NotificationDispatcher::class)->send($user, $type, [
            'plan' => $subscription->plan->value,
            'subscription_id' => $subscription->id,
            'renews_at' => $subscription->renews_at?->toFormattedDateString(),
            'trial_ends_at' => $subscription->trial_ends_at?->toFormattedDateString(),
        ]);
    }
}
