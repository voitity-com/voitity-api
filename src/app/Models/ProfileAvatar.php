<?php

namespace App\Models;

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
        'file',
        'status',
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

    public function isSelectable(): bool
    {
        return in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_INACTIVE], true)
            && filled($this->file);
    }
}
