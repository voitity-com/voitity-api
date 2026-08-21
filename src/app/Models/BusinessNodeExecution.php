<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessNodeExecution extends Model
{
    protected $fillable = [
        'business_conversation_id', 'business_flow_version_id', 'node_key', 'status', 'input', 'output',
        'last_error', 'started_at', 'completed_at',
    ];

    protected $casts = ['input' => 'array', 'output' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
}
