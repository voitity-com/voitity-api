<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VideoAI extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'video_ais';

    protected $fillable = [
        'user_id',
        'profile_id',
        'source_id',
        'source',
        'file',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function profile()
    {
        return $this->belongsTo(Profile::class);
    }
}
