<?php

namespace App\Models;

use App\Enums\ChatAnalysisStatus;
use App\Enums\ConversationCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatAnalysis extends Model
{
    protected $fillable = [
        'chat_id', 'profile_id', 'status', 'primary_category', 'secondary_categories', 'confidence',
        'summary', 'evidence_message_ids', 'model', 'prompt_version', 'taxonomy_version', 'analyzed_at', 'error',
    ];

    protected $casts = [
        'status' => ChatAnalysisStatus::class,
        'primary_category' => ConversationCategory::class,
        'secondary_categories' => 'array',
        'confidence' => 'float',
        'evidence_message_ids' => 'array',
        'analyzed_at' => 'datetime',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
