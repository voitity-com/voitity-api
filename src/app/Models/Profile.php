<?php

namespace App\Models;

use App\Enums\ProfileStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Profile extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'alias',
        'name',
        'description',
        'genre',
        'personality',
        'active',
        'status',
        'data',
    ];

    protected $attributes = [
        'status' => ProfileStatus::Draft->value,
    ];

    protected $casts = [
        'data' => 'array',
        'status' => ProfileStatus::class,
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function voices(): HasMany
    {
        return $this->hasMany(Voice::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function aiVideos(): HasMany
    {
        return $this->hasMany(AiVideo::class);
    }

    public function aiImages(): HasMany
    {
        return $this->hasMany(AiImage::class);
    }

    public function avatars(): HasMany
    {
        return $this->hasMany(ProfileAvatar::class);
    }
}
