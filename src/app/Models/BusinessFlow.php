<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BusinessFlow extends Model
{
    protected $fillable = ['business_id', 'draft_version_id', 'published_version_id'];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BusinessFlowVersion::class);
    }

    public function draftVersion(): BelongsTo
    {
        return $this->belongsTo(BusinessFlowVersion::class, 'draft_version_id');
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(BusinessFlowVersion::class, 'published_version_id');
    }
}
