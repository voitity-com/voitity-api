<?php

namespace App\Classes\Subscriptions;

use App\Classes\PaymentService\PaymentPayloadSanitizer;
use App\Classes\PaymentService\PaymentService;
use App\Classes\PaymentService\PaymentSourceCreateRequest;
use App\Classes\PaymentService\PaymentSourceCreateResult;
use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentProvider;
use App\Exceptions\Subscriptions\PaymentMethodException;
use App\Models\PaymentOrder;
use App\Models\PaymentSource;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentMethodService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentPayloadSanitizer $payloadSanitizer,
    ) {}

    /**
     * @return Collection<int, PaymentSource>
     */
    public function forUser(User $user): Collection
    {
        return PaymentSource::query()
            ->where('user_id', $user->id)
            ->whereNull('disabled_at')
            ->orderByDesc('is_default')
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function setup(): array
    {
        return $this->payments->paymentSourceSetup()->toArray();
    }

    public function create(
        User $user,
        PaymentSourceCreateRequest $request,
        bool $makeDefault = false,
    ): PaymentSource {
        try {
            return Cache::lock("payment-method-registration:{$user->id}", 30)
                ->block(10, function () use ($makeDefault, $request, $user): PaymentSource {
                    $this->ensureCapacity($user);

                    try {
                        $providerSource = $this->payments->createPaymentSource($request);
                    } catch (Throwable $exception) {
                        Log::error('Payment method provider registration request failed.', [
                            'exception' => $exception::class,
                            'user_id' => $user->id,
                        ]);

                        throw new PaymentMethodException(
                            'Wompi could not register the payment method. Try again.',
                            'PAYMENT_PROVIDER_UNAVAILABLE',
                            502,
                        );
                    }

                    if (! $providerSource->isActive()) {
                        Log::warning('Payment method provider registration failed.', [
                            'http_status' => $providerSource->httpStatus,
                            'provider' => $providerSource->source,
                            'provider_status' => $providerSource->providerStatus,
                            'user_id' => $user->id,
                        ]);

                        throw new PaymentMethodException(
                            'Wompi did not confirm an active reusable payment method.',
                            'PAYMENT_METHOD_NOT_REUSABLE',
                        );
                    }

                    return $this->persistProviderSource(
                        $user,
                        $providerSource,
                        $request->metadata,
                        $makeDefault,
                    );
                });
        } catch (LockTimeoutException) {
            throw new PaymentMethodException(
                'Another payment method is being registered. Try again in a few seconds.',
                'PAYMENT_METHOD_REGISTRATION_BUSY',
                409,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function persistProviderSource(
        User $user,
        PaymentSourceCreateResult $providerSource,
        array $metadata = [],
        bool $makeDefault = false,
    ): PaymentSource {
        if (! $providerSource->providerSourceId) {
            throw new PaymentMethodException(
                'The provider did not return a payment method identifier.',
                'PAYMENT_METHOD_IDENTIFIER_MISSING',
            );
        }

        return DB::transaction(function () use ($user, $providerSource, $metadata, $makeDefault): PaymentSource {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $hash = PaymentSource::sourceHash(PaymentProvider::Wompi, $providerSource->providerSourceId);
            $existing = PaymentSource::query()
                ->where('provider', PaymentProvider::Wompi)
                ->where('provider_source_hash', $hash)
                ->lockForUpdate()
                ->first();

            if ($existing instanceof PaymentSource && (int) $existing->user_id !== (int) $lockedUser->id) {
                throw new PaymentMethodException(
                    'The payment method belongs to another account.',
                    'PAYMENT_METHOD_ACCOUNT_MISMATCH',
                );
            }

            if (! $existing instanceof PaymentSource) {
                $this->ensureCapacity($lockedUser);
            }

            $card = $this->cardDetails($providerSource, $metadata);
            $preserveVerifiedState = $existing instanceof PaymentSource
                && $existing->status === 'active'
                && $existing->reusable
                && $existing->disabled_at === null;
            $defaultSource = PaymentSource::query()
                ->where('user_id', $lockedUser->id)
                ->whereNull('disabled_at')
                ->where('is_default', true)
                ->first();
            $shouldBeDefault = $makeDefault
                || ! $defaultSource instanceof PaymentSource
                || ! $defaultSource->isChargeable();

            if ($shouldBeDefault) {
                PaymentSource::query()
                    ->where('user_id', $lockedUser->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }

            $attributes = [
                'user_id' => $lockedUser->id,
                'provider' => PaymentProvider::Wompi,
                'provider_source_id' => $providerSource->providerSourceId,
                'type' => $providerSource->type,
                'card_brand' => $card['brand'] ?? $existing?->card_brand,
                'card_last_four' => $card['last_four'] ?? $existing?->card_last_four,
                'card_exp_month' => $card['exp_month'] ?? $existing?->card_exp_month,
                'card_exp_year' => $card['exp_year'] ?? $existing?->card_exp_year,
                'status' => $preserveVerifiedState ? $existing->status : $providerSource->status,
                'reusable' => $preserveVerifiedState ? $existing->reusable : $providerSource->reusable,
                'metadata' => $this->safeMetadata($providerSource, $metadata),
                'verified_at' => $providerSource->isActive() ? now() : $existing?->verified_at,
                'disabled_at' => null,
                'provider_synced_at' => now(),
                'is_default' => $shouldBeDefault || ($existing?->is_default ?? false),
                'requires_attention' => $providerSource->isActive()
                    ? false
                    : (bool) ($existing?->requires_attention ?? false),
                'last_payment_failure_code' => $providerSource->isActive()
                    ? null
                    : $existing?->last_payment_failure_code,
                'last_payment_failed_at' => $providerSource->isActive()
                    ? null
                    : $existing?->last_payment_failed_at,
                'last_failed_payment_order_id' => $providerSource->isActive()
                    ? null
                    : $existing?->last_failed_payment_order_id,
            ];

            if ($existing instanceof PaymentSource) {
                $existing->fill($attributes)->save();
                $paymentSource = $existing;
            } else {
                $paymentSource = PaymentSource::query()->create($attributes);
            }

            if ($paymentSource->is_default) {
                $lockedUser->subscriptions()
                    ->where('billing_mode', 'recurring')
                    ->where(function ($query): void {
                        $query
                            ->where('active', true)
                            ->orWhereNotNull('payment_failure_code');
                    })
                    ->update(['payment_source_id' => $paymentSource->id]);
            }

            Log::info('Payment method registered.', [
                'is_default' => (bool) $paymentSource->is_default,
                'payment_source_id' => $paymentSource->id,
                'provider' => PaymentProvider::Wompi->value,
                'user_id' => $lockedUser->id,
            ]);

            return $paymentSource->fresh();
        });
    }

    public function sourceForUser(User $user, int $paymentSourceId): PaymentSource
    {
        $source = PaymentSource::query()
            ->whereKey($paymentSourceId)
            ->where('user_id', $user->id)
            ->whereNull('disabled_at')
            ->first();

        if (! $source instanceof PaymentSource) {
            throw new PaymentMethodException('Payment method not found.', 'PAYMENT_METHOD_NOT_FOUND');
        }

        return $source;
    }

    public function setDefault(User $user, PaymentSource $paymentSource): PaymentSource
    {
        return DB::transaction(function () use ($user, $paymentSource): PaymentSource {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $source = PaymentSource::query()
                ->whereKey($paymentSource->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $source instanceof PaymentSource || $source->disabled_at !== null) {
                throw new PaymentMethodException('Payment method not found.', 'PAYMENT_METHOD_NOT_FOUND');
            }

            if (! $source->isChargeable()) {
                throw new PaymentMethodException(
                    'Only an active, reusable and non-expired payment method can be selected.',
                    'PAYMENT_METHOD_NOT_CHARGEABLE',
                );
            }

            PaymentSource::query()
                ->where('user_id', $user->id)
                ->where('id', '!=', $source->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);

            $source->forceFill(['is_default' => true])->save();

            $user->subscriptions()
                ->where('billing_mode', 'recurring')
                ->where(function ($query): void {
                    $query
                        ->where('active', true)
                        ->orWhereNotNull('payment_failure_code');
                })
                ->update(['payment_source_id' => $source->id]);

            Log::info('Default payment method changed.', [
                'payment_source_id' => $source->id,
                'user_id' => $user->id,
            ]);

            return $source->fresh();
        });
    }

    public function disable(User $user, PaymentSource $paymentSource): void
    {
        DB::transaction(function () use ($user, $paymentSource): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $source = PaymentSource::query()
                ->whereKey($paymentSource->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $source instanceof PaymentSource || $source->disabled_at !== null) {
                throw new PaymentMethodException('Payment method not found.', 'PAYMENT_METHOD_NOT_FOUND');
            }

            if ($source->is_default) {
                throw new PaymentMethodException(
                    'Select another default payment method before removing this one.',
                    'DEFAULT_PAYMENT_METHOD_REQUIRED',
                );
            }

            $hasPendingPayment = $source->paymentOrders()
                ->where('status', PaymentOrderStatus::Pending)
                ->exists();

            if ($hasPendingPayment) {
                throw new PaymentMethodException(
                    'This payment method has a pending payment and cannot be removed yet.',
                    'PAYMENT_METHOD_HAS_PENDING_PAYMENT',
                );
            }

            $source->forceFill([
                'disabled_at' => now(),
                'reusable' => false,
                'status' => 'disabled',
            ])->save();

            Log::info('Payment method disabled locally.', [
                'payment_source_id' => $source->id,
                'provider_revocation_supported' => false,
                'user_id' => $user->id,
            ]);
        });
    }

    public function chargeableDefaultFor(User $user, bool $lockForUpdate = false): ?PaymentSource
    {
        $query = PaymentSource::query()
            ->where('user_id', $user->id)
            ->where('is_default', true)
            ->whereNull('disabled_at');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $source = $query->first();

        return $source instanceof PaymentSource && $source->isChargeable() ? $source : null;
    }

    public function chargeableFor(
        User $user,
        int $paymentSourceId,
        bool $lockForUpdate = false,
    ): ?PaymentSource {
        $query = PaymentSource::query()
            ->whereKey($paymentSourceId)
            ->where('user_id', $user->id)
            ->whereNull('disabled_at');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $source = $query->first();

        return $source instanceof PaymentSource && $source->isChargeable() ? $source : null;
    }

    public function retryableDefaultFor(User $user, bool $lockForUpdate = false): ?PaymentSource
    {
        $query = PaymentSource::query()
            ->where('user_id', $user->id)
            ->where('is_default', true)
            ->whereNull('disabled_at');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $source = $query->first();

        return $source instanceof PaymentSource && $source->canAttemptCharge() ? $source : null;
    }

    public function markDefaultAfterApprovedPayment(PaymentSource $paymentSource): PaymentSource
    {
        if (! $paymentSource->user instanceof User) {
            $paymentSource->load('user');
        }

        if (! $paymentSource->user instanceof User) {
            throw new PaymentMethodException(
                'Payment method user not found.',
                'PAYMENT_METHOD_USER_NOT_FOUND',
            );
        }

        $paymentSource->forceFill([
            'requires_attention' => false,
            'last_payment_failure_code' => null,
            'last_payment_failed_at' => null,
            'last_failed_payment_order_id' => null,
        ])->save();

        return $this->setDefault($paymentSource->user, $paymentSource);
    }

    public function markRejectedAfterDeclinedPayment(PaymentOrder $paymentOrder): ?PaymentSource
    {
        if (
            $paymentOrder->status !== PaymentOrderStatus::Declined
            || ! $paymentOrder->payment_source_id
        ) {
            return null;
        }

        return DB::transaction(function () use ($paymentOrder): ?PaymentSource {
            $source = PaymentSource::query()
                ->whereKey($paymentOrder->payment_source_id)
                ->where('user_id', $paymentOrder->user_id)
                ->lockForUpdate()
                ->first();

            if (! $source instanceof PaymentSource) {
                return null;
            }

            $source->forceFill([
                'requires_attention' => true,
                'last_payment_failure_code' => 'payment_declined',
                'last_payment_failed_at' => now(),
                'last_failed_payment_order_id' => $paymentOrder->id,
            ])->save();

            Log::warning('Payment method requires attention after a declined charge.', [
                'billing_reason' => $paymentOrder->billing_reason,
                'payment_order_id' => $paymentOrder->id,
                'payment_source_id' => $source->id,
                'user_id' => $source->user_id,
            ]);

            return $source->fresh();
        });
    }

    public function clearFailureAfterApprovedPayment(PaymentOrder $paymentOrder): ?PaymentSource
    {
        if (
            $paymentOrder->status !== PaymentOrderStatus::Approved
            || ! $paymentOrder->payment_source_id
        ) {
            return null;
        }

        return DB::transaction(function () use ($paymentOrder): ?PaymentSource {
            $source = PaymentSource::query()
                ->whereKey($paymentOrder->payment_source_id)
                ->where('user_id', $paymentOrder->user_id)
                ->lockForUpdate()
                ->first();

            if (! $source instanceof PaymentSource) {
                return null;
            }

            if (
                ! $source->requires_attention
                && ! $source->last_payment_failure_code
                && ! $source->last_payment_failed_at
                && ! $source->last_failed_payment_order_id
            ) {
                return $source;
            }

            $source->forceFill([
                'requires_attention' => false,
                'last_payment_failure_code' => null,
                'last_payment_failed_at' => null,
                'last_failed_payment_order_id' => null,
            ])->save();

            Log::info('Payment method attention state cleared after an approved charge.', [
                'payment_order_id' => $paymentOrder->id,
                'payment_source_id' => $source->id,
                'user_id' => $source->user_id,
            ]);

            return $source->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    public function upsertFromWebhook(
        User $user,
        string $providerSourceId,
        ?string $type,
        bool $approved,
        array $transaction = [],
    ): PaymentSource {
        $result = new PaymentSourceCreateResult(
            source: PaymentProvider::Wompi->value,
            providerSourceId: $providerSourceId,
            type: $type ?: 'CARD',
            providerStatus: $approved ? 'AVAILABLE' : 'PENDING',
            status: $approved ? 'active' : 'pending',
            reusable: true,
            publicData: $this->payloadSanitizer->webhook([
                'data' => ['transaction' => $transaction],
            ]),
        );

        return $this->persistProviderSource($user, $result);
    }

    private function ensureCapacity(User $user): void
    {
        $query = PaymentSource::query()
            ->where('user_id', $user->id)
            ->whereNull('disabled_at');

        $maximum = max(1, (int) config('payment.maximum_payment_methods_per_user', 5));

        if ($query->count() >= $maximum) {
            throw new PaymentMethodException(
                "A maximum of {$maximum} payment methods is allowed.",
                'PAYMENT_METHOD_LIMIT_REACHED',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array{brand:?string,last_four:?string,exp_month:?int,exp_year:?int}
     */
    private function cardDetails(PaymentSourceCreateResult $providerSource, array $metadata): array
    {
        $card = data_get($metadata, 'card');
        $card = is_array($card) ? $card : [];
        $publicData = $providerSource->publicData;
        $brand = $this->stringValue($publicData['brand'] ?? $card['brand'] ?? $publicData['type'] ?? null);
        $number = $this->stringValue(
            $publicData['last_four'] ?? $publicData['number'] ?? $card['last_four'] ?? null
        );
        $digits = $number ? preg_replace('/\D+/', '', $number) : null;
        $year = $this->integerValue($publicData['exp_year'] ?? $card['exp_year'] ?? null);

        return [
            'brand' => $brand ? mb_substr($brand, 0, 50) : null,
            'last_four' => $digits ? substr($digits, -4) : null,
            'exp_month' => $this->integerValue($publicData['exp_month'] ?? $card['exp_month'] ?? null),
            'exp_year' => $year !== null && $year < 100 ? 2000 + $year : $year,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function safeMetadata(PaymentSourceCreateResult $providerSource, array $metadata): array
    {
        return array_filter([
            'provider_status' => $providerSource->providerStatus,
            'wompi_environment' => $this->stringValue($metadata['wompi_environment'] ?? null),
            'provider_diagnostics' => $this->payloadSanitizer->paymentResult($providerSource->toArray()),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function stringValue(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function integerValue(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
