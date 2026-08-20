<?php

namespace App\Models;

use App\Enums\BusinessFlowVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessFlowVersion extends Model
{
    protected $fillable = ['business_flow_id', 'version', 'revision', 'status', 'created_by_user_id', 'published_at'];

    protected $casts = [
        'version' => 'integer', 'revision' => 'integer', 'status' => BusinessFlowVersionStatus::class,
        'published_at' => 'datetime',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(BusinessFlow::class, 'business_flow_id');
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(BusinessFlowNode::class);
    }

    public function edges(): HasMany
    {
        return $this->hasMany(BusinessFlowEdge::class);
    }
}
