<?php

namespace App\Models;

use App\Enums\ProfileStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'product_recommendation_guidance',
        'subscription_suspended_at',
        'suspended_by_subscription_id',
        'subscription_suspension_previous_status',
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
        'active' => 'boolean',
        'data' => 'array',
        'networks' => 'array',
        'products_enabled' => 'boolean',
        'status' => ProfileStatus::class,
        'subscription_suspended_at' => 'datetime',
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

    public function knowledgeIndex(): HasOne
    {
        return $this->hasOne(ProfileKnowledgeIndex::class);
    }

    public function knowledgeChunks(): HasMany
    {
        return $this->hasMany(ProfileKnowledgeChunk::class);
    }

    public function featureSettings(): HasMany
    {
        return $this->hasMany(ProfileFeatureSetting::class);
    }

    public function widget(): HasOne
    {
        return $this->hasOne(ProfileWidget::class);
    }

    public function appearance(): HasOne
    {
        return $this->hasOne(ProfileAppearance::class);
    }

    public function domain(): HasOne
    {
        return $this->hasOne(ProfileDomain::class);
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

    public function interactionEvents(): HasMany
    {
        return $this->hasMany(ProfileInteractionEvent::class);
    }

    public function chatAnalyses(): HasMany
    {
        return $this->hasMany(ChatAnalysis::class);
    }
}
