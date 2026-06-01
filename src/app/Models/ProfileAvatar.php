<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProfileAvatar extends Model
{
    use HasFactory, SoftDeletes;

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
}
