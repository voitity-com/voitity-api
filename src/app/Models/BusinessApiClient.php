<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessApiClient extends Model
{
    protected $fillable = [
        'business_id', 'name', 'public_id', 'key_prefix', 'key_hash', 'enabled', 'expires_at', 'last_used_at',
    ];

    protected $hidden = ['key_hash'];

    protected $casts = ['enabled' => 'boolean', 'expires_at' => 'datetime', 'last_used_at' => 'datetime'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function origins(): HasMany
    {
        return $this->hasMany(BusinessApiClientOrigin::class);
    }
}
