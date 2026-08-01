<?php

namespace App\Models;

use App\Enums\ProfileInsightEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileInteractionEvent extends Model
{
    protected $fillable = [
        'profile_id', 'chat_id', 'visitor_id_hash', 'event_type', 'subject_type', 'subject_id',
        'subject_public_id', 'subject_name', 'subject_status', 'subject_image_url', 'destination_type',
        'provider', 'surface', 'media_type', 'occurred_at', 'metadata', 'idempotency_key',
    ];

    protected $casts = [
        'event_type' => ProfileInsightEventType::class,
        'occurred_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }
}
