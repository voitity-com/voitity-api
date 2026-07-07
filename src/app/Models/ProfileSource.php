<?php

namespace App\Models;

use App\Enums\ProfileSourceStatus;
use App\Enums\ProfileSourceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProfileSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'user_id',
        'type',
        'name',
        'original_filename',
        'mime_type',
        'storage_path',
        'status',
        'extracted_text',
        'parser_version',
        'metadata',
        'last_synced_at',
        'approved_at',
        'indexed_at',
    ];

    protected $casts = [
        'type' => ProfileSourceType::class,
        'status' => ProfileSourceStatus::class,
        'metadata' => 'array',
        'last_synced_at' => 'datetime',
        'approved_at' => 'datetime',
        'indexed_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProfileSourceItem::class);
    }

    public function facts(): HasMany
    {
        return $this->hasMany(ProfileFact::class);
    }
}
