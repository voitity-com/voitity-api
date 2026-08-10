<?php

namespace App\Models;

use App\Enums\AvatarGenerationStatus;
use App\Enums\AvatarVariant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProfileAvatar extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_PROCESSING = 'processing';

    protected $fillable = [
        'user_id',
        'profile_id',
        'aiimage_id',
        'ai_video_id',
        'video_duration_seconds',
        'original_file',
        'file',
        'status',
        'generation_status',
        'selected_variant',
        'failure_code',
        'failure_reason',
    ];

    protected $casts = [
        'video_duration_seconds' => 'integer',
        'generation_status' => AvatarGenerationStatus::class,
        'selected_variant' => AvatarVariant::class,
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function profile()
    {
        return $this->belongsTo(Profile::class);
    }

    public function aiImage()
    {
        return $this->belongsTo(AiImage::class, 'aiimage_id');
    }

    public function aiVideo()
    {
        return $this->belongsTo(AiVideo::class, 'ai_video_id');
    }

    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    public function isSelectable(?AvatarVariant $variant = null): bool
    {
        if ($this->isProcessing()) {
            return false;
        }

        if ($variant) {
            return filled($this->variantFile($variant));
        }

        foreach (AvatarVariant::cases() as $candidate) {
            if (filled($this->variantFile($candidate))) {
                return true;
            }
        }

        return false;
    }

    public function variantFile(AvatarVariant $variant): ?string
    {
        return match ($variant) {
            AvatarVariant::Original => filled($this->original_file) ? $this->original_file : null,
            AvatarVariant::Enhanced => $this->aiImage?->status === 'succeeded' && filled($this->aiImage?->file)
                ? $this->aiImage->file
                : null,
            AvatarVariant::Animation => $this->aiVideo?->status === 'succeeded' && filled($this->aiVideo?->file)
                ? $this->aiVideo->file
                : null,
        };
    }
}
