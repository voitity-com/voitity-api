<?php

namespace App\Models;

use App\Enums\ProfileProductImportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProfileProductImport extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'user_id',
        'original_filename',
        'file_hash',
        'status',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'duplicate_rows',
        'summary',
        'applied_at',
    ];

    protected $casts = [
        'status' => ProfileProductImportStatus::class,
        'summary' => 'array',
        'applied_at' => 'datetime',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ProfileProductImportRow::class);
    }
}
