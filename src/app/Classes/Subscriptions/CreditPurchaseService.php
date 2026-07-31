<?php

namespace App\Classes\Subscriptions;

use App\Classes\PaymentService\PaymentPayloadSanitizer;
use App\Classes\PaymentService\PaymentService;
use App\Classes\PaymentService\PaymentSourceCharge;
use App\Classes\PaymentService\PaymentSourceChargeRequest;
use App\Enums\PaymentCurrency;
use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentProductType;
use App\Enums\PaymentProvider;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Subscriptions\SubscriptionEntitlementException;
use App\Models\CreditWallet;
use App\Models\PaymentOrder;
use App\Models\PaymentSource;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreditPurchaseService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly CreditWalletService $wallets,
        private readonly PaymentMethodService $paymentMethods,
        private readonly PaymentPayloadSanitizer $payloadSanitizer,
    ) {}

    /**
     * @return array{order:PaymentOrder,wallet:CreditWallet}
     */
    public function purchase(
        User $user,
        int $credits,
        string $idempotencyKey,
        ?int $paymentSourceId = null,
    ): array {
        [$order, $created] = DB::transaction(function () use (
            $credits,
            $idempotencyKey,
            $paymentSourceId,
            $user,
        ): array {
            $existing = PaymentOrder::where('purchase_idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof PaymentOrder) {
                if ((int) $existing->user_id !== (int) $user->id) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['The purchase idempotency key is already in use.'],
                    ]);
                }

                if (
                    $existing->product_type !== PaymentProductType::CreditPack
                    || (int) $existing->credit_units !== CreditAmount::creditsToUnits($credits)
                    || (
                        $paymentSourceId !== null
                        && (int) $existing->payment_source_id !== $paymentSourceId
                    )
                ) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => [
                            'The purchase idempotency key was already used with different data.',
                        ],
                    ]);
                }

                return [$existing, false];
            }

            $subscription = $this->activePaidSubscriptionFor($user);
            $paymentSource = $this->activePaymentSourceFor($subscription, $user, $paymentSourceId);
            $amounts = $this->amountsForCredits($credits);

            return [
                PaymentOrder::create([
                    'user_id' => $user->id,
                    'subscription_id' => $subscription->id,
                    'payment_source_id' => $paymentSource->id,
                    'provider' => PaymentProvider::Wompi,
                    'reference' => $this->uniqueReference($user->id),
                    'product_type' => PaymentProductType::CreditPack,
                    'product_code' => "credits-{$credits}",
                    'credit_units' => CreditAmount::creditsToUnits($credits),
                    'purchase_idempotency_key' => $idempotencyKey,
                    'plan' => $subscription->plan,
                    'recurring' => false,
                    'billing_reason' => 'credit_purchase',
                    'customer_terms_version' => config('subscriptions.customer_terms_version'),
                    'customer_terms_accepted_at' => now(),
                    'accepted_plan_price_usd' => null,
                    'display_amount_usd' => $amounts['display_amount_usd'],
                    'display_currency' => PaymentCurrency::Usd,
                    'exchange_rate' => $amounts['exchange_rate'],
                    'amount_cop' => $amounts['amount_cop'],
                    'amount_in_cents' => $amounts['amount_in_cents'],
                    'currency' => PaymentCurrency::Cop,
                    'status' => PaymentOrderStatus::Pending,
                ]),
                true,
            ];
        });

        if (! $created) {
            if ($order->status === PaymentOrderStatus::Approved) {
                $wallet = $this->wallets->grantForPaymentOrder($order);
            } else {
                $wallet = $this->wallets->walletForUser($user);
            }

            return ['order' => $order, 'wallet' => $wallet];
        }

        $order->loadMissing('user', 'paymentSource');
        $charge = $this->payments->chargePaymentSource(new PaymentSourceChargeRequest(
            reference: $order->reference,
            amountInCents: $order->amount_in_cents,
            currency: $order->currency->value,
            customerEmail: (string) $order->user?->email,
            paymentSourceProviderId: (string) $order->paymentSource?->provider_source_id,
            installments: 1,
            recurrent: false,
        ));

        if ($charge->isPending() && $charge->providerTransactionId) {
            $charge = $this->resolvePendingCharge($order, $charge);
        }

        $order->provider_transaction_id = $charge->providerTransactionId;
        $order->wompi_status = $charge->providerStatus;
        $order->raw_provider_payload = $this->payloadSanitizer->paymentResult($charge->toArray());
        $order->status = PaymentOrderStatus::from($charge->status);

        if ($order->status === PaymentOrderStatus::Approved) {
            $order->paid_at = now();
        }

        $order->save();
        $order->paymentSource?->forceFill(['last_used_at' => now()])->save();

        if ($order->status === PaymentOrderStatus::Declined) {
            $this->paymentMethods->markRejectedAfterDeclinedPayment($order);
        } elseif ($order->status === PaymentOrderStatus::Approved) {
            $this->paymentMethods->clearFailureAfterApprovedPayment($order);
        }

        $wallet = $order->status === PaymentOrderStatus::Approved
            ? $this->wallets->grantForPaymentOrder($order)
            : $this->wallets->walletForUser($user);

        $this->dispatchNotification($order, $credits);

        Log::info('Credit purchase processed.', [
            'credits' => $credits,
            'payment_order_id' => $order->id,
            'payment_source_id' => $order->payment_source_id,
            'requested_payment_source_id' => $paymentSourceId,
            'status' => $order->status->value,
            'user_id' => $user->id,
        ]);

        return ['order' => $order->fresh(), 'wallet' => $wallet->fresh()];
    }

    private function activePaidSubscriptionFor(User $user): Subscription
    {
        $subscription = Subscription::query()
            ->where('user_id', $user->id)
            ->where('active', true)
            ->where('renews_at', '>', now())
            ->latest('started_at')
            ->lockForUpdate()
            ->first();

        $planConfig = $subscription instanceof Subscription
            ? config("subscriptions.plans.{$subscription->plan->value}", [])
            : [];

        if (
            ! $subscription instanceof Subscription
            || $subscription->status === SubscriptionStatus::Trialing
            || $subscription->billing_mode !== 'recurring'
            || ($planConfig['purchasable'] ?? false) !== true
        ) {
            throw new SubscriptionEntitlementException(
                'An active paid subscription is required to purchase credits.',
                ['subscription' => ['An active paid subscription is required to purchase credits.']]
            );
        }

        return $subscription;
    }

    private function activePaymentSourceFor(
        Subscription $subscription,
        User $user,
        ?int $paymentSourceId,
    ): PaymentSource {
        $paymentSource = $paymentSourceId === null
            ? $this->paymentMethods->chargeableDefaultFor($user, true)
            : $this->paymentMethods->chargeableFor($user, $paymentSourceId, true);

        if (! $paymentSource instanceof PaymentSource) {
            Log::warning('Credit purchase payment method selection rejected.', [
                'payment_source_id' => $paymentSourceId,
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
            ]);

            throw new SubscriptionEntitlementException(
                'A valid active reusable payment method is required to purchase credits.',
                ['payment_source' => ['A valid active reusable payment method is required to purchase credits.']],
                422,
            );
        }

        if (
            $paymentSource->is_default
            && (int) $subscription->payment_source_id !== (int) $paymentSource->id
        ) {
            $subscription->forceFill(['payment_source_id' => $paymentSource->id])->save();
        }

        return $paymentSource;
    }

    /**
     * @return array{display_amount_usd:float,exchange_rate:float,amount_cop:float,amount_in_cents:int}
     */
    private function amountsForCredits(int $credits): array
    {
        $pricePerThousand = max(0.01, (float) config('subscriptions.credit_store.price_per_1000_usd', 10));
        $displayAmountUsd = round(($credits / 1000) * $pricePerThousand, 2);
        $exchangeRate = (float) config('payment.usd_cop_rate', 4000);
        $amountInCents = (int) round($displayAmountUsd * $exchangeRate * 100);

        return [
            'display_amount_usd' => $displayAmountUsd,
            'exchange_rate' => $exchangeRate,
            'amount_cop' => round($amountInCents / 100, 2),
            'amount_in_cents' => $amountInCents,
        ];
    }

    private function resolvePendingCharge(PaymentOrder $order, PaymentSourceCharge $charge): PaymentSourceCharge
    {
        $attempts = max(0, (int) config('payment.pending_charge_poll_attempts', 3));
        $delayMs = max(0, (int) config('payment.pending_charge_poll_delay_ms', 500));

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            if ($delayMs > 0) {
                usleep($delayMs * 1000);
            }

            $charge = $this->payments->getPaymentSourceCharge(
                providerTransactionId: (string) $charge->providerTransactionId,
                reference: $order->reference,
                amountInCents: $order->amount_in_cents,
                currency: $order->currency->value,
            );

            if (! $charge->isPending()) {
                break;
            }
        }

        return $charge;
    }

    private function uniqueReference(int $userId): string
    {
        do {
            $reference = 'VOI-CRD-'.$userId.'-'.Str::upper(Str::random(12));
        } while (PaymentOrder::where('reference', $reference)->exists());

        return $reference;
    }

    private function dispatchNotification(PaymentOrder $order, int $credits): void
    {
        $order->loadMissing('user');

        if (! $order->user instanceof User) {
            return;
        }

        $data = [
            'amount' => sprintf('USD %.2f', (float) $order->display_amount_usd),
            'credits' => number_format($credits),
            'payment_order_id' => $order->id,
            'plan' => 'credits',
            'reference' => $order->reference,
        ];
        $dispatcher = app(NotificationDispatcher::class);

        if ($order->status === PaymentOrderStatus::Approved) {
            $dispatcher->send($order->user, 'credits_purchased', $data);
        } elseif ($order->status === PaymentOrderStatus::Pending) {
            $dispatcher->sendInApp($order->user, 'payment_pending', $data);
        } else {
            $dispatcher->send($order->user, 'failed_payment', $data);
        }
    }
}
