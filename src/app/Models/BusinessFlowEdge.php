<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessFlowEdge extends Model
{
    protected $fillable = [
        'business_flow_version_id', 'edge_key', 'source_node_key', 'target_node_key', 'source_handle', 'label', 'config',
    ];

    protected $casts = ['config' => 'array'];

    public function version(): BelongsTo
    {
        return $this->belongsTo(BusinessFlowVersion::class, 'business_flow_version_id');
    }
}
