<?php

namespace App\Models;

use App\Enums\BusinessFlowNodeType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessFlowNode extends Model
{
    protected $fillable = [
        'business_flow_version_id', 'node_key', 'type', 'title', 'position_x', 'position_y', 'config',
    ];

    protected $casts = [
        'type' => BusinessFlowNodeType::class, 'position_x' => 'integer', 'position_y' => 'integer', 'config' => 'array',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(BusinessFlowVersion::class, 'business_flow_version_id');
    }
}
