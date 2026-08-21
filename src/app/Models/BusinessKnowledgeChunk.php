<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessKnowledgeChunk extends Model
{
    protected $fillable = [
        'business_id', 'business_source_id', 'chunk_key', 'content', 'content_hash', 'token_count',
        'metadata', 'active', 'embedding_model', 'embedding_dimensions', 'embedded_at',
    ];

    protected $casts = [
        'token_count' => 'integer',
        'metadata' => 'array',
        'active' => 'boolean',
        'embedding_dimensions' => 'integer',
        'embedded_at' => 'datetime',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(BusinessSource::class, 'business_source_id');
    }
}
