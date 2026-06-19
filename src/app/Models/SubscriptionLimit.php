<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionLimit extends Model
{
    protected $fillable = [
        'subscription_id',
        'user_id',
        'period_started_at',
        'period_renews_at',
        'profiles_remaining',
        'avatar_images_remaining',
        'avatar_video_seconds_remaining',
        'voice_clones_remaining',
        'tts_characters_remaining',
        'chat_messages_remaining',
        'credits_remaining',
    ];

    protected $casts = [
        'period_started_at' => 'datetime',
        'period_renews_at' => 'datetime',
        'credits_remaining' => 'float',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
