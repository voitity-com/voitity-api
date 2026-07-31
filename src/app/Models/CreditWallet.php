<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditWallet extends Model
{
    protected $fillable = [
        'user_id',
        'available_units',
        'reserved_units',
        'debt_units',
        'lifetime_purchased_units',
        'lifetime_consumed_units',
    ];

    protected $casts = [
        'available_units' => 'integer',
        'reserved_units' => 'integer',
        'debt_units' => 'integer',
        'lifetime_purchased_units' => 'integer',
        'lifetime_consumed_units' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CreditLedgerEntry::class);
    }
}
