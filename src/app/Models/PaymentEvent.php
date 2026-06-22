<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEvent extends Model
{
    protected $fillable = [
        'payment_order_id',
        'provider',
        'provider_event_id',
        'event_type',
        'checksum',
        'is_valid_signature',
        'payload',
        'processed_at',
    ];

    protected $casts = [
        'provider' => PaymentProvider::class,
        'is_valid_signature' => 'boolean',
        'payload' => 'array',
        'processed_at' => 'datetime',
    ];

    public function paymentOrder(): BelongsTo
    {
        return $this->belongsTo(PaymentOrder::class);
    }
}
