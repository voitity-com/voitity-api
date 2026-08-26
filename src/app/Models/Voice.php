<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Voice extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'profile_id',
        'name',
        'description',
        'language_code',
        'source_voice_id',
        'source',
        'is_verified',
        'verified_at',
        'active',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'active' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function profile()
    {
        return $this->belongsTo(Profile::class);
    }

    public function providerRequests(): HasMany
    {
        return $this->hasMany(VoiceProviderRequest::class);
    }

    public function latestProviderRequest(): HasOne
    {
        return $this->hasOne(VoiceProviderRequest::class)->latestOfMany();
    }
}
