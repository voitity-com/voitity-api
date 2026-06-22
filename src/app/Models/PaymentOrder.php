<?php

namespace App\Models;

use App\Enums\PaymentCurrency;
use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentProvider;
use App\Enums\SubscriptionPlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentOrder extends Model
{
    protected $fillable = [
        'user_id',
        'subscription_id',
        'provider',
        'reference',
        'provider_transaction_id',
        'plan',
        'display_amount_usd',
        'display_currency',
        'exchange_rate',
        'amount_cop',
        'amount_in_cents',
        'currency',
        'status',
        'wompi_status',
        'checkout_url',
        'raw_provider_payload',
        'paid_at',
        'expires_at',
    ];

    protected $casts = [
        'provider' => PaymentProvider::class,
        'plan' => SubscriptionPlan::class,
        'display_amount_usd' => 'float',
        'display_currency' => PaymentCurrency::class,
        'exchange_rate' => 'float',
        'amount_cop' => 'float',
        'amount_in_cents' => 'integer',
        'currency' => PaymentCurrency::class,
        'status' => PaymentOrderStatus::class,
        'raw_provider_payload' => 'array',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }
}
