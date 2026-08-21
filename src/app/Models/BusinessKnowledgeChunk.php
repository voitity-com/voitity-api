<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessKnowledgeChunk extends Model
{
    protected $fillable = ['business_id', 'business_source_id', 'chunk_key', 'content', 'token_count', 'metadata'];

    protected $casts = ['token_count' => 'integer', 'metadata' => 'array'];

    public function source(): BelongsTo
    {
        return $this->belongsTo(BusinessSource::class, 'business_source_id');
    }
}
