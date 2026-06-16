<?php

namespace App\Models;

use App\Enums\SubscriptionUsageType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionUse extends Model
{
    protected $table = 'subscription_uses';

    protected $fillable = [
        'subscription_id',
        'user_id',
        'profile_id',
        'usage_type',
        'source_type',
        'source_id',
        'idempotency_key',
        'profiles_used',
        'avatar_images_used',
        'avatar_video_seconds_used',
        'voice_clones_used',
        'tts_characters_used',
        'chat_messages_used',
        'metadata',
        'used_at',
    ];

    protected $casts = [
        'usage_type' => SubscriptionUsageType::class,
        'metadata' => 'array',
        'used_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
