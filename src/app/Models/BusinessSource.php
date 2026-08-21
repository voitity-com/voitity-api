<?php

namespace App\Models;

use App\Enums\BusinessSourceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessSource extends Model
{
    protected $fillable = [
        'business_id', 'user_id', 'type', 'name', 'original_filename', 'mime_type', 'storage_path',
        'status', 'extracted_text', 'token_count', 'metadata', 'last_error', 'indexed_at',
    ];

    protected $casts = [
        'status' => BusinessSourceStatus::class,
        'token_count' => 'integer',
        'metadata' => 'array',
        'indexed_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(BusinessKnowledgeChunk::class);
    }
}
