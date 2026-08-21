<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessMessage extends Model
{
    protected $fillable = [
        'business_conversation_id', 'node_key', 'role', 'content', 'data',
        'input_tokens', 'output_tokens', 'total_tokens',
    ];

    protected $casts = [
        'data' => 'array', 'input_tokens' => 'integer', 'output_tokens' => 'integer', 'total_tokens' => 'integer',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(BusinessConversation::class, 'business_conversation_id');
    }
}
