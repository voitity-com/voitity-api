<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileAppearance extends Model
{
    use HasFactory;

    public const BACKGROUND_CSS = 'css';

    public const BACKGROUND_IMAGE = 'image';

    protected $fillable = [
        'profile_id',
        'template_key',
        'background_type',
        'background_image_disk',
        'background_image_path',
        'metadata',
    ];

    protected $attributes = [
        'template_key' => 'profile01',
        'background_type' => self::BACKGROUND_CSS,
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
