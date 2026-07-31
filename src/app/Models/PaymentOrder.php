<?php

namespace App\Models;

use App\Enums\PaymentCurrency;
use App\Enums\PaymentOrderStatus;
use App\Enums\PaymentProductType;
use App\Enums\PaymentProvider;
use App\Enums\SubscriptionPlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentOrder extends Model
{
    protected $attributes = [
        'product_type' => 'subscription',
        'credit_units' => 0,
    ];

    protected $fillable = [
        'user_id',
        'subscription_id',
        'source_subscription_id',
        'payment_source_id',
        'provider',
        'reference',
        'provider_transaction_id',
        'product_type',
        'product_code',
        'credit_units',
        'purchase_idempotency_key',
        'plan',
        'recurring',
        'billing_reason',
        'billing_cycle_at',
        'attempt_number',
        'customer_terms_version',
        'customer_terms_accepted_at',
        'accepted_plan_price_usd',
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
        'product_type' => PaymentProductType::class,
        'credit_units' => 'integer',
        'plan' => SubscriptionPlan::class,
        'recurring' => 'boolean',
        'billing_cycle_at' => 'datetime',
        'attempt_number' => 'integer',
        'customer_terms_accepted_at' => 'datetime',
        'accepted_plan_price_usd' => 'float',
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

    public function sourceSubscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'source_subscription_id');
    }

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class);
    }
}
