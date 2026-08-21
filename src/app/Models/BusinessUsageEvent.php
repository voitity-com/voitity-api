<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessUsageEvent extends Model
{
    protected $fillable = [
        'business_id', 'business_conversation_id', 'business_source_id', 'business_message_id', 'event_type',
        'provider', 'model', 'input_tokens', 'output_tokens', 'total_tokens', 'metadata', 'occurred_at',
    ];

    protected $casts = [
        'input_tokens' => 'integer', 'output_tokens' => 'integer', 'total_tokens' => 'integer',
        'metadata' => 'array', 'occurred_at' => 'datetime',
    ];
}
