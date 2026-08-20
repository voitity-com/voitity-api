<?php

namespace App\Models;

use App\Enums\BusinessLeadStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessLead extends Model
{
    protected $fillable = [
        'business_id', 'business_conversation_id', 'status', 'full_name', 'email', 'phone', 'whatsapp',
        'company', 'website', 'project_summary', 'ai_solution_summary', 'data', 'contacted_at', 'sold_at',
        'no_response_at',
    ];

    protected $casts = [
        'status' => BusinessLeadStatus::class, 'data' => 'array', 'contacted_at' => 'datetime',
        'sold_at' => 'datetime', 'no_response_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(BusinessConversation::class, 'business_conversation_id');
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(BusinessLeadStatusHistory::class);
    }
}
