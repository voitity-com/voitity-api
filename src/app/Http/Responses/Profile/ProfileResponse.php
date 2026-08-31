<?php

namespace App\Http\Responses\Profile;

use App\Classes\ProfilePublication\ProfilePublicationReadinessService;
use App\Classes\Subscriptions\ProfileMessagingCapabilitiesService;
use App\Models\Profile;
use App\Models\Voice;
use App\Models\VoiceProviderRequest;
use App\Services\Features\FeatureService;
use App\Services\ProfileConversationMessageService;
use App\Services\ProfileVoiceSettings;

class ProfileResponse
{
    public function __construct(private readonly Profile $profile) {}

    public function toArray(): array
    {
        $activeVoice = $this->activeVoice();
        $voiceSettings = app(ProfileVoiceSettings::class);

        return [
            'id' => $this->profile->id,
            'user_id' => $this->profile->user_id,
            'alias' => $this->profile->alias,
            'name' => $this->profile->name,
            'description' => $this->profile->description,
            'genre' => $this->profile->genre,
            'personality' => $this->profile->personality,
            'locale' => $this->profile->locale ?: 'es',
            'profession_key' => $this->profile->profession_key,
            'profession_template_version' => $this->profile->profession_template_version,
            'active' => (bool) $this->profile->active,
            'status' => $this->profile->status?->value,
            'voice' => $activeVoice ? $voiceSettings->voiceIsConfigured($activeVoice) : false,
            'voice_id' => $activeVoice?->id,
            'voice_name' => $activeVoice?->name,
            'voice_description' => $activeVoice?->description,
            'voice_enabled' => $voiceSettings->voiceEnabled($this->profile),
            'voice_autoplay_enabled' => $voiceSettings->voiceAutoplayEnabled($this->profile),
            'voice_clone_status' => $this->voiceCloneStatus($activeVoice, $voiceSettings),
            'voice_language_code' => $activeVoice?->language_code,
            'publication' => app(ProfilePublicationReadinessService::class)->evaluate($this->profile),
            'conversation_messages' => app(ProfileConversationMessageService::class)->resolvedMessages($this->profile),
            'data' => $this->profile->data,
            'networks' => (object) ($this->profile->networks ?? []),
            'products_enabled' => (bool) $this->profile->products_enabled,
            'feature_settings' => app(FeatureService::class)->profileFeatureRows($this->profile),
            'messaging_capabilities' => app(ProfileMessagingCapabilitiesService::class)->forProfile($this->profile),
            'appearance' => ProfileAppearanceResponse::forProfile($this->profile)->toArray(),
            'created_at' => $this->profile->created_at?->toJSON(),
            'updated_at' => $this->profile->updated_at?->toJSON(),
        ];
    }

    private function activeVoice(): ?Voice
    {
        if ($this->profile->relationLoaded('voices')) {
            return $this->profile->voices->first(
                fn (Voice $voice) => (bool) $voice->active
            );
        }

        if (! $this->profile->exists) {
            return null;
        }

        return $this->profile->voices()
            ->where('active', true)
            ->latest('id')
            ->first();
    }

    private function voiceCloneStatus(?Voice $voice, ProfileVoiceSettings $voiceSettings): ?string
    {
        if (! $voice) {
            return null;
        }

        if ($voice->relationLoaded('latestProviderRequest')) {
            return $voice->latestProviderRequest?->status
                ?? ($voiceSettings->voiceIsConfigured($voice) ? VoiceProviderRequest::STATUS_COMPLETED : null);
        }

        if ($voice->exists) {
            return $voice->latestProviderRequest()->value('status')
                ?? ($voiceSettings->voiceIsConfigured($voice) ? VoiceProviderRequest::STATUS_COMPLETED : null);
        }

        return $voiceSettings->voiceIsConfigured($voice) ? VoiceProviderRequest::STATUS_COMPLETED : null;
    }
}
