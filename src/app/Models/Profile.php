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
        'locale',
        'profession_key',
        'profession_template_version',
        'active',
        'status',
        'data',
        'networks',
        'products_enabled',
    ];

    protected $attributes = [
        'active' => false,
        'status' => ProfileStatus::Draft->value,
        'locale' => 'es',
        'profession_key' => 'custom',
        'profession_template_version' => '2026-07',
        'networks' => '{}',
        'products_enabled' => false,
    ];

    protected $casts = [
        'data' => 'array',
        'networks' => 'array',
        'products_enabled' => 'boolean',
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

    public function conversationMessages(): HasMany
    {
        return $this->hasMany(ProfileConversationMessage::class);
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

    public function sources(): HasMany
    {
        return $this->hasMany(ProfileSource::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(ProfileIntegration::class);
    }

    public function integrationMedia(): HasMany
    {
        return $this->hasMany(ProfileIntegrationMedia::class);
    }

    public function facts(): HasMany
    {
        return $this->hasMany(ProfileFact::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(ProfileProduct::class);
    }

    public function productImports(): HasMany
    {
        return $this->hasMany(ProfileProductImport::class);
    }

    public function subscriptionUses(): HasMany
    {
        return $this->hasMany(SubscriptionUse::class);
    }
}
