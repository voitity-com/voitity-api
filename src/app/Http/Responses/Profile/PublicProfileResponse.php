<?php

namespace App\Http\Responses\Profile;

use App\Classes\Subscriptions\ProfileMessagingCapabilitiesService;
use App\Models\Profile;
use App\Services\Features\FeatureService;
use App\Services\ProfileConversationMessageService;

class PublicProfileResponse
{
    public function __construct(private readonly Profile $profile) {}

    public function toArray(): array
    {
        return [
            'id' => $this->profile->id,
            'alias' => $this->profile->alias,
            'name' => $this->profile->name,
            'description' => $this->profile->description,
            'genre' => $this->profile->genre,
            'personality' => $this->profile->personality,
            'locale' => $this->profile->locale ?: 'es',
            'profession_key' => $this->profile->profession_key,
            'conversation_messages' => app(ProfileConversationMessageService::class)
                ->resolvedMessages($this->profile),
            'data' => $this->profile->data,
            'networks' => (object) ($this->profile->networks ?? []),
            'products_enabled' => (bool) $this->profile->products_enabled,
            'feature_settings' => app(FeatureService::class)
                ->profileFeatureRows($this->profile),
            'messaging_capabilities' => app(ProfileMessagingCapabilitiesService::class)
                ->forProfile($this->profile),
        ];
    }
}
