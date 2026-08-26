<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Profile;
use App\Models\Voice;
use Illuminate\Support\Facades\DB;

class ProfileVoiceSettings
{
    public const VOICE_ENABLED_KEY = 'voice_enabled';

    public const VOICE_AUTOPLAY_ENABLED_KEY = 'voice_autoplay_enabled';

    public const VOICE_SETTINGS_INITIALIZED_KEY = 'voice_settings_initialized';

    public function voiceEnabled(Profile $profile): bool
    {
        return $this->hasConfiguredVoice($profile)
            && $this->booleanFromData($profile, self::VOICE_ENABLED_KEY, true);
    }

    public function voiceAutoplayEnabled(Profile $profile): bool
    {
        return $this->voiceEnabled($profile)
            && $this->booleanFromData($profile, self::VOICE_AUTOPLAY_ENABLED_KEY, true);
    }

    public function shouldGenerateResponseAudio(Profile $profile, Message $question): bool
    {
        if (! $this->voiceEnabled($profile)) {
            return false;
        }

        $data = $question->data ?? [];
        $request = isset($data['request']) && is_array($data['request'])
            ? $data['request']
            : [];

        return $this->normalizeBoolean(
            $request['audio_response_enabled'] ?? true,
            true
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function mergedData(Profile $profile, bool $voiceEnabled, bool $voiceAutoplayEnabled): array
    {
        $data = is_array($profile->data) ? $profile->data : [];

        $data[self::VOICE_ENABLED_KEY] = $voiceEnabled;
        $data[self::VOICE_AUTOPLAY_ENABLED_KEY] = $voiceEnabled && $voiceAutoplayEnabled;

        return $data;
    }

    public function hasConfiguredVoice(Profile $profile): bool
    {
        if ($profile->relationLoaded('voices')) {
            return $profile->voices->contains(
                fn (Voice $voice): bool => (bool) $voice->active && $this->voiceIsConfigured($voice)
            );
        }

        if (! $profile->exists) {
            return false;
        }

        return $profile->voices()
            ->where('active', true)
            ->whereNotNull('source_voice_id')
            ->where('source_voice_id', '<>', '')
            ->whereNotNull('source')
            ->where('source', '<>', '')
            ->exists();
    }

    public function voiceIsConfigured(Voice $voice): bool
    {
        return filled($voice->source_voice_id) && filled($voice->source);
    }

    public function initializeAfterSuccessfulClone(Voice $voice, bool $hadConfiguredVoice): void
    {
        if (! $voice->profile_id) {
            return;
        }

        DB::transaction(function () use ($hadConfiguredVoice, $voice): void {
            $profile = Profile::query()->lockForUpdate()->find($voice->profile_id);

            if (! $profile instanceof Profile) {
                return;
            }

            $data = is_array($profile->data) ? $profile->data : [];

            if (! array_key_exists(self::VOICE_SETTINGS_INITIALIZED_KEY, $data)) {
                if (! $hadConfiguredVoice) {
                    $data[self::VOICE_ENABLED_KEY] = true;
                    $data[self::VOICE_AUTOPLAY_ENABLED_KEY] = true;
                }

                $data[self::VOICE_SETTINGS_INITIALIZED_KEY] = true;
                $profile->forceFill(['data' => $data])->save();
            }
        });
    }

    private function booleanFromData(Profile $profile, string $key, bool $default): bool
    {
        $data = is_array($profile->data) ? $profile->data : [];

        return $this->normalizeBoolean($data[$key] ?? $default, $default);
    }

    private function normalizeBoolean(mixed $value, bool $default): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            return is_bool($normalized) ? $normalized : $default;
        }

        return $default;
    }
}
