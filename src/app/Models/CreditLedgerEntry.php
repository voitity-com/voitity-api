<?php

namespace App\Models;

use App\Enums\CreditLedgerEntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditLedgerEntry extends Model
{
    protected $fillable = [
        'credit_wallet_id',
        'user_id',
        'subscription_use_id',
        'payment_order_id',
        'type',
        'amount_units',
        'available_units_after',
        'reserved_units_after',
        'debt_units_after',
        'idempotency_key',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'type' => CreditLedgerEntryType::class,
        'amount_units' => 'integer',
        'available_units_after' => 'integer',
        'reserved_units_after' => 'integer',
        'debt_units_after' => 'integer',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(CreditWallet::class, 'credit_wallet_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscriptionUse(): BelongsTo
    {
        return $this->belongsTo(SubscriptionUse::class);
    }

    public function paymentOrder(): BelongsTo
    {
        return $this->belongsTo(PaymentOrder::class);
    }
}
