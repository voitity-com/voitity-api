<?php

namespace App\Models;

use App\Enums\BusinessConversationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class BusinessConversation extends Model
{
    protected $fillable = [
        'uuid', 'business_id', 'business_flow_version_id', 'business_api_client_id', 'status',
        'current_node_key', 'context', 'origin', 'visitor_id_hash', 'started_at', 'last_activity_at',
        'completed_at', 'end_reason',
    ];

    protected $casts = [
        'status' => BusinessConversationStatus::class, 'context' => 'array', 'started_at' => 'datetime',
        'last_activity_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(BusinessFlowVersion::class, 'business_flow_version_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(BusinessMessage::class);
    }

    public function lead(): HasOne
    {
        return $this->hasOne(BusinessLead::class);
    }
}
