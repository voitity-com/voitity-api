<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

class PaymentSource extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_source_id',
        'provider_source_ciphertext',
        'provider_source_hash',
        'type',
        'card_brand',
        'card_last_four',
        'card_exp_month',
        'card_exp_year',
        'status',
        'reusable',
        'is_default',
        'requires_attention',
        'last_payment_failure_code',
        'last_payment_failed_at',
        'last_failed_payment_order_id',
        'metadata',
        'verified_at',
        'last_used_at',
        'disabled_at',
        'provider_synced_at',
    ];

    protected $casts = [
        'provider' => PaymentProvider::class,
        'reusable' => 'boolean',
        'is_default' => 'boolean',
        'requires_attention' => 'boolean',
        'last_payment_failed_at' => 'datetime',
        'metadata' => 'array',
        'card_exp_month' => 'integer',
        'card_exp_year' => 'integer',
        'verified_at' => 'datetime',
        'last_used_at' => 'datetime',
        'disabled_at' => 'datetime',
        'provider_synced_at' => 'datetime',
    ];

    protected $hidden = [
        'provider_source_id',
        'provider_source_ciphertext',
        'provider_source_hash',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::creating(function (PaymentSource $source): void {
            if ($source->is_default || ! $source->user_id) {
                return;
            }

            $source->is_default = ! self::query()
                ->where('user_id', $source->user_id)
                ->whereNull('disabled_at')
                ->where('is_default', true)
                ->exists();
        });
    }

    public function getProviderSourceIdAttribute(mixed $value): ?string
    {
        $ciphertext = $this->attributes['provider_source_ciphertext'] ?? null;

        if (is_string($ciphertext) && $ciphertext !== '') {
            try {
                return Crypt::decryptString($ciphertext);
            } catch (DecryptException) {
                return null;
            }
        }

        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }

    public function setProviderSourceIdAttribute(mixed $value): void
    {
        $providerSourceId = is_scalar($value) ? trim((string) $value) : '';

        if ($providerSourceId === '') {
            $this->attributes['provider_source_id'] = null;
            $this->attributes['provider_source_ciphertext'] = null;
            $this->attributes['provider_source_hash'] = null;

            return;
        }

        $provider = $this->attributes['provider'] ?? PaymentProvider::Wompi->value;
        $provider = $provider instanceof PaymentProvider ? $provider->value : (string) $provider;

        $this->attributes['provider_source_id'] = null;
        $this->attributes['provider_source_ciphertext'] = Crypt::encryptString($providerSourceId);
        $this->attributes['provider_source_hash'] = self::sourceHash($provider, $providerSourceId);
    }

    public static function sourceHash(PaymentProvider|string $provider, string $providerSourceId): string
    {
        $providerValue = $provider instanceof PaymentProvider ? $provider->value : $provider;

        return hash('sha256', strtolower(trim($providerValue)).':'.trim($providerSourceId));
    }

    public function isExpired(?Carbon $now = null): bool
    {
        if (! $this->card_exp_month || ! $this->card_exp_year) {
            return false;
        }

        $now ??= now();

        return Carbon::create(
            $this->card_exp_year,
            $this->card_exp_month,
            1,
            0,
            0,
            0,
            $now->timezone
        )->endOfMonth()->isBefore($now);
    }

    public function isChargeable(?Carbon $now = null): bool
    {
        return $this->canAttemptCharge($now)
            && ! $this->requires_attention;
    }

    public function canAttemptCharge(?Carbon $now = null): bool
    {
        return $this->disabled_at === null
            && $this->status === 'active'
            && $this->reusable
            && filled($this->provider_source_id)
            && ! $this->isExpired($now);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentOrders(): HasMany
    {
        return $this->hasMany(PaymentOrder::class);
    }

    public function lastFailedPaymentOrder(): BelongsTo
    {
        return $this->belongsTo(PaymentOrder::class, 'last_failed_payment_order_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
