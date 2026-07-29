<?php

namespace App\Classes\Subscriptions;

use App\Classes\PaymentService\PaymentService;
use App\Classes\PaymentService\PaymentSourceCreateRequest;
use App\Classes\PaymentService\PaymentSourceCreateResult;
use App\Enums\PaymentCurrency;
use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\PaymentOrder;
use App\Models\PaymentSource;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class SubscriptionTrialService
{
    public function __construct(
        private readonly SubscriptionPlanCatalog $planCatalog,
        private readonly SubscriptionLimitPeriodService $limitPeriods,
        private readonly ?SubscriptionProfileAccessService $profileAccess = null,
    ) {}

    /**
     * @return array{subscription:Subscription,payment_source:PaymentSource,payment_order:PaymentOrder,provider_source:PaymentSourceCreateResult}
     */
    public function startTrialWithPaymentSource(
        User $user,
        SubscriptionPlan $plan,
        PaymentService $paymentService,
        PaymentSourceCreateRequest $paymentSourceRequest,
        CustomerTermsAcceptance $termsAcceptance,
    ): array {
        $this->ensureTrialCanStart($user, $plan);

        $providerSource = $paymentService->createPaymentSource($paymentSourceRequest);

        if (! $providerSource->isActive()) {
            throw new RuntimeException('Wompi did not confirm an active reusable payment source.');
        }

        [$paymentSource, $paymentOrder] = DB::transaction(function () use ($user, $plan, $paymentSourceRequest, $providerSource, $termsAcceptance): array {
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->ensureTrialCanStart($lockedUser, $plan);
            $paymentSource = $this->localPaymentSourceFor($lockedUser, $providerSource, $paymentSourceRequest->metadata);
            $paymentOrder = $this->createTrialSetupOrder(
                $lockedUser,
                $plan,
                $paymentSource,
                $providerSource,
                $termsAcceptance,
            );

            return [$paymentSource, $paymentOrder];
        });

        $subscription = $this->activateTrialFromPaymentOrder($paymentOrder);
        $this->dispatchSubscriptionNotification($user, 'trial_started', $subscription);

        return [
            'subscription' => $subscription,
            'payment_source' => $paymentSource,
            'payment_order' => $paymentOrder,
            'provider_source' => $providerSource,
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

                Log::info('Subscription renewal cancellation scheduled.', [
                    'plan' => $subscription->plan->value,
                    'renews_at' => $subscription->renews_at?->toJSON(),
                    'subscription_id' => $subscription->id,
                    'user_id' => $user->id,
                ]);
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

            Log::info('Subscription renewal reactivated before service end.', [
                'plan' => $subscription->plan->value,
                'renews_at' => $subscription->renews_at?->toJSON(),
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
            ]);

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
                            $deactivatedProfiles = $this->profileAccess()
                                ->deactivateProfilesIfAccessEnded(
                                    $lockedSubscription->user,
                                    'cancelled_subscription_period_ended',
                                    $lockedSubscription->id
                                );

                            $this->dispatchSubscriptionNotification(
                                $lockedSubscription->user,
                                'subscription_expired',
                                $lockedSubscription,
                            );

                            Log::warning('Cancelled subscription reached its service end.', [
                                'deactivated_profile_count' => $deactivatedProfiles,
                                'plan' => $lockedSubscription->plan->value,
                                'subscription_id' => $lockedSubscription->id,
                                'user_id' => $lockedSubscription->user_id,
                            ]);
                        }

                        $expired++;
                    });
                }
            });

        return $expired;
    }

    private function profileAccess(): SubscriptionProfileAccessService
    {
        return $this->profileAccess ?? app(SubscriptionProfileAccessService::class);
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

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function localPaymentSourceFor(User $user, PaymentSourceCreateResult $providerSource, array $metadata = []): PaymentSource
    {
        $existingSource = PaymentSource::query()
            ->where('provider', PaymentProvider::Wompi)
            ->where('provider_source_id', $providerSource->providerSourceId)
            ->first();

        if ($existingSource instanceof PaymentSource && $existingSource->user_id !== $user->id) {
            throw new RuntimeException('The payment source belongs to another account.');
        }

        $attributes = [
            'user_id' => $user->id,
            'provider' => PaymentProvider::Wompi,
            'provider_source_id' => $providerSource->providerSourceId,
            'type' => $providerSource->type,
            'status' => $providerSource->status,
            'reusable' => $providerSource->reusable,
            'metadata' => $this->paymentSourceMetadata($providerSource, $metadata),
            'verified_at' => $providerSource->isActive() ? now() : null,
        ];

        if ($existingSource instanceof PaymentSource) {
            $existingSource->fill($attributes);
            $existingSource->save();

            return $existingSource;
        }

        /** @var PaymentSource $paymentSource */
        $paymentSource = PaymentSource::query()->create($attributes);

        return $paymentSource;
    }

    private function createTrialSetupOrder(
        User $user,
        SubscriptionPlan $plan,
        PaymentSource $paymentSource,
        PaymentSourceCreateResult $providerSource,
        CustomerTermsAcceptance $termsAcceptance,
    ): PaymentOrder {
        $exchangeRate = (float) config('payment.usd_cop_rate', 4000);

        if ($exchangeRate <= 0) {
            throw new RuntimeException('Invalid USD to COP exchange rate configuration.');
        }

        /** @var PaymentOrder $paymentOrder */
        $paymentOrder = PaymentOrder::query()->create([
            'user_id' => $user->id,
            'payment_source_id' => $paymentSource->id,
            'provider' => PaymentProvider::Wompi,
            'reference' => $this->uniqueReference($user->id),
            'plan' => $plan,
            'recurring' => true,
            'billing_reason' => 'trial_setup',
            ...$termsAcceptance->paymentOrderAttributes($plan, $this->planCatalog),
            'display_amount_usd' => 0,
            'display_currency' => PaymentCurrency::Usd,
            'exchange_rate' => $exchangeRate,
            'amount_cop' => 0,
            'amount_in_cents' => 0,
            'currency' => PaymentCurrency::Cop,
            'status' => PaymentOrderStatus::Approved,
            'wompi_status' => $providerSource->providerStatus,
            'raw_provider_payload' => $providerSource->toArray(),
        ]);

        return $paymentOrder;
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentSourceMetadata(PaymentSourceCreateResult $providerSource, array $metadata = []): array
    {
        return array_filter([
            'provider_status' => $providerSource->providerStatus,
            'public_data' => $providerSource->publicData,
            'metadata' => $metadata,
            'http_status' => $providerSource->httpStatus,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
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
