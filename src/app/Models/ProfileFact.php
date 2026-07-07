<?php

namespace App\Models;

use App\Enums\ProfileFactVisibility;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileFact extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'profile_source_id',
        'profile_source_item_id',
        'category',
        'text',
        'visibility',
        'approved',
        'indexed',
        'metadata',
    ];

    protected $casts = [
        'visibility' => ProfileFactVisibility::class,
        'approved' => 'boolean',
        'indexed' => 'boolean',
        'metadata' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ProfileSource::class, 'profile_source_id');
    }

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(ProfileSourceItem::class, 'profile_source_item_id');
    }
}
