<?php

namespace App\Models;

use App\Enums\SubscriptionPlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SubscriptionUsagePeriod extends Model
{
    protected $fillable = [
        'subscription_id',
        'user_id',
        'plan',
        'period_started_at',
        'period_renews_at',
        'limits_snapshot',
    ];

    protected $casts = [
        'plan' => SubscriptionPlan::class,
        'period_started_at' => 'datetime',
        'period_renews_at' => 'datetime',
        'limits_snapshot' => 'array',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function limit(): HasOne
    {
        return $this->hasOne(SubscriptionLimit::class, 'usage_period_id');
    }

    public function uses(): HasMany
    {
        return $this->hasMany(SubscriptionUse::class, 'usage_period_id');
    }
}
