<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BusinessActionRun extends Model
{
    protected $fillable = [
        'business_id', 'business_conversation_id', 'node_key', 'action_key', 'idempotency_key', 'status',
        'payload', 'last_error', 'attempts', 'executed_at',
    ];

    protected $casts = ['payload' => 'array', 'attempts' => 'integer', 'executed_at' => 'datetime'];
}
