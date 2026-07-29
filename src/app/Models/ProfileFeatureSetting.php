<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileFeatureSetting extends Model
{
    protected $fillable = [
        'profile_id',
        'feature_key',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
