<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileIntegrationMedia extends Model
{
    use HasFactory;

    protected $table = 'profile_integration_media';

    protected $fillable = [
        'profile_integration_id',
        'profile_id',
        'provider',
        'provider_media_id',
        'media_type',
        'media_url',
        'thumbnail_url',
        'permalink',
        'caption',
        'observation',
        'selected',
        'taken_at',
        'metadata',
    ];

    protected $casts = [
        'selected' => 'boolean',
        'taken_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function integration(): BelongsTo
    {
        return $this->belongsTo(ProfileIntegration::class, 'profile_integration_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
