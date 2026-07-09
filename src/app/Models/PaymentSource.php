<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentSource extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_source_id',
        'type',
        'status',
        'reusable',
        'metadata',
        'verified_at',
        'last_used_at',
    ];

    protected $casts = [
        'provider' => PaymentProvider::class,
        'reusable' => 'boolean',
        'metadata' => 'array',
        'verified_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentOrders(): HasMany
    {
        return $this->hasMany(PaymentOrder::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
