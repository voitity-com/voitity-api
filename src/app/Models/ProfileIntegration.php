<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProfileIntegration extends Model
{
    use HasFactory;

    public const PROVIDER_INSTAGRAM = 'instagram';

    public const PROVIDER_TIKTOK = 'tiktok';

    public const PROVIDER_ONLYFANS = 'onlyfans';

    public const PROVIDER_OTHER = 'other';

    public const PROVIDER_YOUTUBE = 'youtube';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_ERROR = 'error';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'profile_id',
        'user_id',
        'provider',
        'provider_user_id',
        'username',
        'access_token',
        'refresh_token',
        'token_type',
        'scopes',
        'expires_at',
        'refresh_expires_at',
        'last_synced_at',
        'status',
        'metadata',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'scopes' => 'array',
        'expires_at' => 'datetime',
        'refresh_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProfileIntegrationMedia::class);
    }
}
