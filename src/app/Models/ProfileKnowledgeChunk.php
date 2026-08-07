<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileKnowledgeChunk extends Model
{
    protected $fillable = [
        'profile_id',
        'chunk_key',
        'source_type',
        'source_id',
        'locale',
        'content',
        'content_hash',
        'metadata',
        'visibility',
        'active',
        'embedding_model',
        'embedding_dimensions',
        'embedded_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'active' => 'boolean',
        'embedding_dimensions' => 'integer',
        'embedded_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
