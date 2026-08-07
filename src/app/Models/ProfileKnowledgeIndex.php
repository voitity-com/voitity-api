<?php

namespace App\Models;

use App\Enums\ProfileKnowledgeIndexStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileKnowledgeIndex extends Model
{
    protected $table = 'profile_knowledge_indexes';

    protected $fillable = [
        'profile_id',
        'status',
        'index_version',
        'embedding_model',
        'embedding_dimensions',
        'total_chunks',
        'active_chunks',
        'embedding_tokens',
        'last_error',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'status' => ProfileKnowledgeIndexStatus::class,
        'embedding_dimensions' => 'integer',
        'total_chunks' => 'integer',
        'active_chunks' => 'integer',
        'embedding_tokens' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
