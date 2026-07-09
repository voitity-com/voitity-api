<?php

namespace App\Models;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'payment_source_id',
        'source_payment_order_id',
        'plan',
        'billing_mode',
        'started_at',
        'renews_at',
        'status',
        'active',
        'cancel_at_period_end',
        'cancelled_at',
        'last_billed_at',
        'next_billing_at',
    ];

    protected $casts = [
        'plan' => SubscriptionPlan::class,
        'status' => SubscriptionStatus::class,
        'started_at' => 'datetime',
        'renews_at' => 'datetime',
        'active' => 'boolean',
        'cancel_at_period_end' => 'boolean',
        'cancelled_at' => 'datetime',
        'last_billed_at' => 'datetime',
        'next_billing_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentSource(): BelongsTo
    {
        return $this->belongsTo(PaymentSource::class);
    }

    public function limit(): HasOne
    {
        return $this->hasOne(SubscriptionLimit::class);
    }

    public function uses(): HasMany
    {
        return $this->hasMany(SubscriptionUse::class);
    }
}
