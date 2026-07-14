<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileConversationMessage extends Model
{
    use HasFactory;

    public const TYPE_INITIAL = 'initial';

    public const TYPE_FALLBACK_NO_ANSWER = 'fallback_no_answer';

    public const AUDIO_SOURCE_DEFAULT = 'default';

    public const AUDIO_SOURCE_GENERATED = 'generated';

    public const AUDIO_SOURCE_RECORDED = 'recorded';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING = 'pending';

    protected $fillable = [
        'profile_id',
        'type',
        'text',
        'audio_url',
        'audio_path',
        'audio_disk',
        'audio_source',
        'audio_format',
        'voice_id',
        'status',
        'text_hash',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function voice(): BelongsTo
    {
        return $this->belongsTo(Voice::class);
    }
}
