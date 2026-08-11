<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ProfileWidget extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'public_key',
        'enabled',
    ];

    protected $attributes = [
        'enabled' => false,
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (ProfileWidget $widget): void {
            if (! filled($widget->public_key)) {
                $widget->public_key = (string) Str::uuid();
            }
        });
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
