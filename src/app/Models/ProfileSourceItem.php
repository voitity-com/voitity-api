<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProfileSourceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_source_id',
        'profile_id',
        'type',
        'title',
        'content',
        'structured_data',
        'confidence',
        'approved',
        'indexed',
        'source_url',
        'metadata',
    ];

    protected $casts = [
        'structured_data' => 'array',
        'confidence' => 'float',
        'approved' => 'boolean',
        'indexed' => 'boolean',
        'metadata' => 'array',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(ProfileSource::class, 'profile_source_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function facts(): HasMany
    {
        return $this->hasMany(ProfileFact::class);
    }
}
