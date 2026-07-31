<?php

namespace App\Classes\Subscriptions;

use App\Classes\PaymentService\CopAmount;
use App\Classes\PaymentService\PaymentPayloadSanitizer;
use App\Classes\PaymentService\PaymentService;
use App\Classes\PaymentService\PaymentSourceCharge;
use App\Classes\PaymentService\PaymentSourceChargeRequest;
use App\Enums\PaymentCurrency;
use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Subscriptions\SubscriptionPaymentRecoveryException;
use App\Models\PaymentOrder;
use App\Models\PaymentSource;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SubscriptionBillingService
{
    public function __construct(
        private readonly PaymentService $paymentService,
        private readonly SubscriptionPlanCatalog $planCatalog,
        private readonly SubscriptionPlanActivator $subscriptionPlanActivator,
        private readonly PaymentMethodService $paymentMethods,
        private readonly PaymentPayloadSanitizer $payloadSanitizer,
        private readonly ?SubscriptionProfileAccessService $profileAccess = null,
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
        $maximumAttempts = $this->maximumAutomaticAttempts();

        Subscription::query()
            ->where('billing_mode', 'recurring')
            ->where('cancel_at_period_end', false)
            ->where(function ($query) use ($maximumAttempts, $now): void {
                $query
                    ->where(function ($query) use ($now): void {
                        $query
                            ->where('active', true)
                            ->where(function ($query) use ($now): void {
                                $query
                                    ->where('next_billing_at', '<=', $now)
                                    ->orWhere(function ($query) use ($now): void {
                                        $query
                                            ->whereNull('next_billing_at')
                                            ->where('renews_at', '<=', $now);
                                    });
                            });
                    })
                    ->orWhere(function ($query) use ($maximumAttempts, $now): void {
                        $query
                            ->where('active', false)
                            ->where('status', SubscriptionStatus::PastDue->value)
                            ->whereNotNull('payment_failure_code')
                            ->whereNotNull('next_payment_retry_at')
                            ->where('next_payment_retry_at', '<=', $now)
                            ->where('payment_retry_count', '<', $maximumAttempts);
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

    /**
     * @return array{outcome:string,order:?PaymentOrder,source_subscription:Subscription,subscription:?Subscription}
     */
    public function retryFailedRenewal(User $user, ?Carbon $now = null): array
    {
        $now ??= now();
        $subscription = Subscription::query()
            ->where('user_id', $user->id)
            ->where('billing_mode', 'recurring')
            ->where('status', SubscriptionStatus::PastDue->value)
            ->whereNotNull('payment_failure_code')
            ->latest('started_at')
            ->first();

        if (! $subscription instanceof Subscription) {
            throw new SubscriptionPaymentRecoveryException(
                'No failed subscription renewal is available to retry.',
                'SUBSCRIPTION_PAYMENT_RECOVERY_NOT_AVAILABLE',
            );
        }

        if (! $this->paymentMethods->chargeableDefaultFor($user)) {
            throw new SubscriptionPaymentRecoveryException(
                'Add or select an active reusable payment method before renewing.',
                'PAYMENT_METHOD_REQUIRED',
            );
        }

        Log::info('Manual subscription payment retry requested.', [
            'payment_retry_count' => $subscription->payment_retry_count,
            'source_subscription_id' => $subscription->id,
            'user_id' => $user->id,
        ]);

        $outcome = $this->billSubscription($subscription, $now, true);
        $order = PaymentOrder::query()
            ->where('source_subscription_id', $subscription->id)
            ->latest('id')
            ->first();
        $activatedSubscription = $order?->subscription()->first();

        if ($outcome === 'skipped') {
            throw new SubscriptionPaymentRecoveryException(
                'The subscription renewal could not be retried.',
                'SUBSCRIPTION_PAYMENT_RETRY_SKIPPED',
                409,
            );
        }

        return [
            'outcome' => $outcome,
            'order' => $order,
            'source_subscription' => $subscription->fresh(),
            'subscription' => $activatedSubscription,
        ];
    }

    public function recordFailedPaymentOrder(PaymentOrder $paymentOrder, ?Carbon $now = null): void
    {
        if (
            ! $paymentOrder->recurring
            || ! in_array($paymentOrder->billing_reason, ['subscription_renewal', 'trial_conversion'], true)
            || in_array($paymentOrder->status, [PaymentOrderStatus::Approved, PaymentOrderStatus::Pending], true)
        ) {
            return;
        }

        $now ??= now();

        DB::transaction(function () use ($now, $paymentOrder): void {
            $subscription = $paymentOrder->source_subscription_id
                ? Subscription::query()
                    ->whereKey($paymentOrder->source_subscription_id)
                    ->lockForUpdate()
                    ->first()
                : Subscription::query()
                    ->where('user_id', $paymentOrder->user_id)
                    ->where('billing_mode', 'recurring')
                    ->where(function ($query): void {
                        $query
                            ->where('active', true)
                            ->orWhere('status', SubscriptionStatus::PastDue->value);
                    })
                    ->latest('started_at')
                    ->lockForUpdate()
                    ->first();

            if (! $subscription instanceof Subscription) {
                Log::warning('Recurring payment failure has no source subscription.', [
                    'payment_order_id' => $paymentOrder->id,
                    'user_id' => $paymentOrder->user_id,
                ]);

                return;
            }

            if (! $paymentOrder->source_subscription_id) {
                $paymentOrder->forceFill([
                    'source_subscription_id' => $subscription->id,
                    'billing_cycle_at' => $subscription->next_billing_at ?? $subscription->renews_at,
                ])->save();
            }

            $this->recordPaymentFailureLocked(
                $subscription,
                'payment_'.$paymentOrder->status->value,
                $paymentOrder,
                $now,
            );
        });
    }

    public function maximumAutomaticAttempts(): int
    {
        return count($this->retryHours()) + 1;
    }

    private function billSubscription(
        Subscription $subscription,
        Carbon $now,
        bool $manual = false,
    ): string {
        $prepared = DB::transaction(function () use ($manual, $now, $subscription): array {
            /** @var Subscription|null $lockedSubscription */
            $lockedSubscription = Subscription::query()
                ->with('user')
                ->whereKey($subscription->id)
                ->lockForUpdate()
                ->first();

            if (
                ! $lockedSubscription instanceof Subscription
                || ! $this->isDueForBilling($lockedSubscription, $now, $manual)
                || ! $this->planCatalog->isPurchasable($lockedSubscription->plan)
            ) {
                return ['outcome' => 'skipped'];
            }

            $billingReason = $this->billingReasonFor($lockedSubscription);
            $pendingOrder = $this->pendingRenewalOrderFor($lockedSubscription, $billingReason);

            if ($pendingOrder instanceof PaymentOrder) {
                Log::info('Subscription payment retry blocked by pending payment.', [
                    'manual' => $manual,
                    'payment_order_id' => $pendingOrder->id,
                    'source_subscription_id' => $lockedSubscription->id,
                    'user_id' => $lockedSubscription->user_id,
                ]);

                return [
                    'created' => false,
                    'order' => $pendingOrder,
                    'outcome' => 'pending',
                ];
            }

            $paymentSource = $manual
                ? $this->paymentMethods->chargeableDefaultFor($lockedSubscription->user, true)
                : $this->paymentMethods->retryableDefaultFor($lockedSubscription->user, true);

            if (! $paymentSource instanceof PaymentSource) {
                $this->recordPaymentFailureLocked(
                    $lockedSubscription,
                    'payment_method_required',
                    null,
                    $now,
                );

                return ['outcome' => 'failed'];
            }

            if ((int) $lockedSubscription->payment_source_id !== (int) $paymentSource->id) {
                $lockedSubscription->forceFill(['payment_source_id' => $paymentSource->id])->save();
            }

            return [
                'created' => true,
                'order' => $this->createBillingOrder(
                    $lockedSubscription,
                    $paymentSource,
                    $billingReason,
                ),
                'outcome' => 'pending',
            ];
        });

        $paymentOrder = $prepared['order'] ?? null;

        if (! ($prepared['created'] ?? false)) {
            return (string) ($prepared['outcome'] ?? 'skipped');
        }

        if (! $paymentOrder instanceof PaymentOrder) {
            return 'skipped';
        }

        try {
            $charge = $this->paymentService->chargePaymentSource(new PaymentSourceChargeRequest(
                reference: $paymentOrder->reference,
                amountInCents: $paymentOrder->amount_in_cents,
                currency: $paymentOrder->currency->value,
                customerEmail: (string) $paymentOrder->user?->email,
                paymentSourceProviderId: (string) $paymentOrder->paymentSource?->provider_source_id,
                installments: 1,
                recurrent: true,
            ));

            if ($charge->isPending() && $charge->providerTransactionId) {
                $charge = $this->resolvePendingCharge($paymentOrder, $charge);
            }

            $this->applyChargeResult($paymentOrder, $charge);
        } catch (Throwable $exception) {
            Log::error('Recurring subscription payment request failed.', [
                'exception' => $exception::class,
                'payment_order_id' => $paymentOrder->id,
                'payment_source_id' => $paymentOrder->payment_source_id,
                'source_subscription_id' => $subscription->id,
                'user_id' => $paymentOrder->user_id,
            ]);

            $paymentOrder->forceFill([
                'status' => PaymentOrderStatus::Error,
                'wompi_status' => 'PAYMENT_REQUEST_FAILED',
                'raw_provider_payload' => ['local_error' => 'payment_request_failed'],
            ])->save();
        }

        $paymentOrder->refresh();
        $paymentOrder->paymentSource?->forceFill(['last_used_at' => now()])->save();

        if ($paymentOrder->status === PaymentOrderStatus::Declined) {
            $this->paymentMethods->markRejectedAfterDeclinedPayment($paymentOrder);
        } elseif ($paymentOrder->status === PaymentOrderStatus::Approved) {
            $this->paymentMethods->clearFailureAfterApprovedPayment($paymentOrder);
        }

        if ($paymentOrder->status === PaymentOrderStatus::Approved) {
            $this->subscriptionPlanActivator->activateForPaymentOrder($paymentOrder);
            $paymentOrder->refresh();
            $this->logPaymentResult($paymentOrder, $subscription, $manual);
            $this->dispatchRenewalNotifications($paymentOrder);

            return 'approved';
        }

        if ($paymentOrder->status === PaymentOrderStatus::Pending) {
            $this->logPaymentResult($paymentOrder, $subscription, $manual);
            $this->dispatchRenewalNotifications($paymentOrder);

            return 'pending';
        }

        $this->recordFailedPaymentOrder($paymentOrder, $now);
        $this->logPaymentResult($paymentOrder, $subscription, $manual);
        $this->dispatchRenewalNotifications($paymentOrder);

        return 'failed';
    }

    private function applyChargeResult(
        PaymentOrder $paymentOrder,
        PaymentSourceCharge $charge,
    ): void {
        $paymentOrder->provider_transaction_id = $charge->providerTransactionId;
        $paymentOrder->wompi_status = $charge->providerStatus;
        $paymentOrder->raw_provider_payload = $this->payloadSanitizer->paymentResult($charge->toArray());
        $paymentOrder->status = PaymentOrderStatus::from($charge->status);

        if ($paymentOrder->status === PaymentOrderStatus::Approved) {
            $paymentOrder->paid_at = now();
        }

        $paymentOrder->save();
    }

    private function recordPaymentFailureLocked(
        Subscription $subscription,
        string $failureCode,
        ?PaymentOrder $paymentOrder,
        Carbon $now,
    ): void {
        if (
            $paymentOrder instanceof PaymentOrder
            && (int) $subscription->last_failed_payment_order_id === (int) $paymentOrder->id
        ) {
            Log::info('Duplicate recurring payment failure ignored.', [
                'payment_order_id' => $paymentOrder->id,
                'source_subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
            ]);

            return;
        }

        $retryCount = (int) $subscription->payment_retry_count + 1;
        $nextRetryAt = $this->nextRetryAt($retryCount, $now);

        $subscription->forceFill([
            'active' => false,
            'status' => SubscriptionStatus::PastDue,
            'payment_failure_code' => $failureCode,
            'payment_failed_at' => $now,
            'payment_retry_count' => $retryCount,
            'next_payment_retry_at' => $nextRetryAt,
            'last_failed_payment_order_id' => $paymentOrder?->id,
            'access_ended_reason' => 'payment_failure',
        ])->save();

        $deactivatedProfiles = $this->profileAccess()->deactivateProfilesIfAccessEnded(
            $subscription->user_id,
            $failureCode,
            $subscription->id,
        );

        Log::warning('Subscription access suspended after payment failure.', [
            'automatic_retries_remaining' => max(0, $this->maximumAutomaticAttempts() - $retryCount),
            'deactivated_profile_count' => $deactivatedProfiles,
            'failure_code' => $failureCode,
            'next_payment_retry_at' => $nextRetryAt?->toJSON(),
            'payment_order_id' => $paymentOrder?->id,
            'payment_retry_count' => $retryCount,
            'source_subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
        ]);
    }

    private function resolvePendingCharge(
        PaymentOrder $paymentOrder,
        PaymentSourceCharge $charge,
    ): PaymentSourceCharge {
        $attempts = max(0, (int) config('payment.pending_charge_poll_attempts', 3));
        $delayMs = max(0, (int) config('payment.pending_charge_poll_delay_ms', 500));

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $refreshedCharge = $this->paymentService->getPaymentSourceCharge(
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

    private function isDueForBilling(
        Subscription $subscription,
        Carbon $now,
        bool $manual,
    ): bool {
        if (
            $subscription->billing_mode !== 'recurring'
            || $subscription->cancel_at_period_end
        ) {
            return false;
        }

        if ($manual) {
            return ! $subscription->active
                && $subscription->status === SubscriptionStatus::PastDue
                && filled($subscription->payment_failure_code);
        }

        if ($subscription->active) {
            $dueAt = $subscription->next_billing_at ?? $subscription->renews_at;

            return $dueAt->lessThanOrEqualTo($now);
        }

        return $subscription->status === SubscriptionStatus::PastDue
            && filled($subscription->payment_failure_code)
            && $subscription->next_payment_retry_at?->lessThanOrEqualTo($now)
            && $subscription->payment_retry_count < $this->maximumAutomaticAttempts();
    }

    private function pendingRenewalOrderFor(
        Subscription $subscription,
        string $billingReason,
    ): ?PaymentOrder {
        $billingCycleAt = $subscription->next_billing_at ?? $subscription->renews_at;

        return PaymentOrder::query()
            ->where('user_id', $subscription->user_id)
            ->where('plan', $subscription->plan)
            ->where('recurring', true)
            ->where('billing_reason', $billingReason)
            ->where('status', PaymentOrderStatus::Pending)
            ->where(function ($query) use ($billingCycleAt, $subscription): void {
                $query
                    ->where('source_subscription_id', $subscription->id)
                    ->orWhere(function ($query) use ($billingCycleAt): void {
                        $query
                            ->whereNull('source_subscription_id')
                            ->where('created_at', '>=', $billingCycleAt);
                    });
            })
            ->orderByDesc('id')
            ->first();
    }

    private function createBillingOrder(
        Subscription $subscription,
        PaymentSource $paymentSource,
        string $billingReason,
    ): PaymentOrder {
        $amounts = $this->amountsForPlan($subscription);

        return PaymentOrder::query()->create([
            'user_id' => $subscription->user_id,
            'source_subscription_id' => $subscription->id,
            'payment_source_id' => $paymentSource->id,
            'provider' => PaymentProvider::Wompi,
            'reference' => $this->uniqueReference($subscription->user_id, $billingReason),
            'plan' => $subscription->plan,
            'recurring' => true,
            'billing_reason' => $billingReason,
            'billing_cycle_at' => $subscription->next_billing_at ?? $subscription->renews_at,
            'attempt_number' => (int) $subscription->payment_retry_count + 1,
            'display_amount_usd' => $amounts['display_amount_usd'],
            'display_currency' => PaymentCurrency::Usd,
            'exchange_rate' => $amounts['exchange_rate'],
            'amount_cop' => $amounts['amount_cop'],
            'amount_in_cents' => $amounts['amount_in_cents'],
            'currency' => PaymentCurrency::Cop,
            'status' => PaymentOrderStatus::Pending,
        ]);
    }

    private function billingReasonFor(Subscription $subscription): string
    {
        return (
            $subscription->status === SubscriptionStatus::Trialing
            || ($subscription->trial_started_at !== null && $subscription->trial_converted_at === null)
        )
            ? 'trial_conversion'
            : 'subscription_renewal';
    }

    /**
     * @return array{display_amount_usd:float,exchange_rate:float,amount_cop:int,amount_in_cents:int}
     */
    private function amountsForPlan(Subscription $subscription): array
    {
        $planConfig = $this->planCatalog->configFor($subscription->plan);
        $displayAmountUsd = round((float) ($planConfig['price_usd'] ?? 0), 2);
        $exchangeRate = (float) config('payment.usd_cop_rate', 4000);
        $amountCop = CopAmount::fromUsd($displayAmountUsd, $exchangeRate);

        return [
            'display_amount_usd' => $displayAmountUsd,
            'exchange_rate' => $exchangeRate,
            'amount_cop' => $amountCop->pesos,
            'amount_in_cents' => $amountCop->inCents(),
        ];
    }

    private function uniqueReference(int $userId, string $billingReason): string
    {
        $prefix = $billingReason === 'trial_conversion' ? 'VOI-TCV' : 'VOI-REN';

        do {
            $reference = $prefix.'-'.$userId.'-'.Str::upper(Str::random(12));
        } while (PaymentOrder::where('reference', $reference)->exists());

        return $reference;
    }

    private function nextRetryAt(int $retryCount, Carbon $now): ?Carbon
    {
        $hours = $this->retryHours()[$retryCount - 1] ?? null;

        return $hours === null ? null : $now->copy()->addHours($hours);
    }

    /**
     * @return list<int>
     */
    private function retryHours(): array
    {
        return collect(config('subscriptions.payment_retry_hours', [6, 24, 72]))
            ->filter(fn (mixed $hours): bool => is_numeric($hours) && (int) $hours > 0)
            ->map(fn (mixed $hours): int => (int) $hours)
            ->values()
            ->all();
    }

    private function logPaymentResult(
        PaymentOrder $paymentOrder,
        Subscription $sourceSubscription,
        bool $manual,
    ): void {
        Log::info('Recurring subscription payment processed.', [
            'attempt_number' => $paymentOrder->attempt_number,
            'billing_reason' => $paymentOrder->billing_reason,
            'manual' => $manual,
            'payment_order_id' => $paymentOrder->id,
            'payment_source_id' => $paymentOrder->payment_source_id,
            'source_subscription_id' => $sourceSubscription->id,
            'status' => $paymentOrder->status->value,
            'subscription_id' => $paymentOrder->subscription_id,
            'user_id' => $paymentOrder->user_id,
        ]);
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
            $dispatcher->send(
                $user,
                $paymentOrder->billing_reason === 'trial_conversion'
                    ? 'trial_converted_to_paid'
                    : 'successful_subscription_renewal',
                $data,
            );

            return;
        }

        if ($paymentOrder->status === PaymentOrderStatus::Pending) {
            $dispatcher->sendInApp($user, 'payment_pending', $data);

            return;
        }

        $dispatcher->send(
            $user,
            $paymentOrder->billing_reason === 'trial_conversion'
                ? 'trial_payment_failed'
                : 'failed_subscription_renewal',
            $data,
        );
    }

    private function profileAccess(): SubscriptionProfileAccessService
    {
        return $this->profileAccess ?? app(SubscriptionProfileAccessService::class);
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
