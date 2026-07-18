<?php

namespace App\Classes\Subscriptions;

use App\Classes\PaymentService\PaymentService;
use App\Classes\PaymentService\PaymentSourceCharge;
use App\Classes\PaymentService\PaymentSourceChargeRequest;
use App\Classes\PaymentService\PaymentSourceCreateRequest;
use App\Classes\PaymentService\PaymentSourceCreateResult;
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
use Illuminate\Support\Str;
use RuntimeException;

class SubscriptionPaymentSourceService
{
    public function __construct(
        private readonly SubscriptionPlanCatalog $planCatalog,
        private readonly SubscriptionPlanActivator $subscriptionPlanActivator,
    ) {}

    /**
     * @return array{subscription:?Subscription,payment_source:PaymentSource,payment_order:PaymentOrder,provider_source:PaymentSourceCreateResult,charge:PaymentSourceCharge}
     */
    public function startSubscriptionWithPaymentSource(
        User $user,
        SubscriptionPlan $plan,
        PaymentService $paymentService,
        PaymentSourceCreateRequest $paymentSourceRequest,
    ): array {
        $this->ensureSubscriptionCanStart($user, $plan);

        $providerSource = $paymentService->createPaymentSource($paymentSourceRequest);

        if (! $providerSource->isActive()) {
            throw new RuntimeException('Wompi did not confirm an active reusable payment source.');
        }

        [$paymentSource, $paymentOrder] = DB::transaction(function () use ($user, $plan, $paymentSourceRequest, $providerSource): array {
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $this->ensureSubscriptionCanStart($lockedUser, $plan);
            $paymentSource = $this->localPaymentSourceFor($lockedUser, $providerSource, $paymentSourceRequest->metadata);
            $paymentOrder = $this->createInitialOrder($lockedUser, $plan, $paymentSource);

            return [$paymentSource, $paymentOrder];
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
        $paymentOrder->raw_provider_payload = $charge->toArray();
        $paymentOrder->status = PaymentOrderStatus::from($charge->status);

        if ($paymentOrder->status === PaymentOrderStatus::Approved) {
            $paymentOrder->paid_at = $chargedAt;
        }

        $paymentOrder->save();
        $paymentSource->forceFill(['last_used_at' => $chargedAt])->save();

        $subscription = null;

        if ($paymentOrder->status === PaymentOrderStatus::Approved) {
            $subscription = $this->subscriptionPlanActivator->activateForPaymentOrder($paymentOrder);
        }

        $this->dispatchPaymentNotification($paymentOrder->fresh(['user']));

        return [
            'subscription' => $subscription,
            'payment_source' => $paymentSource->fresh(),
            'payment_order' => $paymentOrder->fresh(),
            'provider_source' => $providerSource,
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

    private function createInitialOrder(User $user, SubscriptionPlan $plan, PaymentSource $paymentSource): PaymentOrder
    {
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
