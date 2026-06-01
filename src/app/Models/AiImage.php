<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiImage extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'aiimages';

    protected $fillable = [
        'user_id',
        'profile_id',
        'source_id',
        'source',
        'status',
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

    public function aiVideos()
    {
        return $this->hasMany(AiVideo::class, 'aiimage_id');
    }
}
