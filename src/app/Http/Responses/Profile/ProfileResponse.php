<?php

namespace App\Http\Responses\Profile;

use App\Models\Profile;

class ProfileResponse
{
    public function __construct(private readonly Profile $profile)
    {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->profile->id,
            'user_id' => $this->profile->user_id,
            'name' => $this->profile->name,
            'description' => $this->profile->description,
            'genre' => $this->profile->genre,
            'personality' => $this->profile->personality,
            'active' => (bool) $this->profile->active,
            'voice' => $this->hasConfiguredVoice(),
            'data' => $this->profile->data,
            'created_at' => $this->profile->created_at?->toJSON(),
            'updated_at' => $this->profile->updated_at?->toJSON(),
        ];
    }

    private function hasConfiguredVoice(): bool
    {
        if ($this->profile->relationLoaded('voices')) {
            return $this->profile->voices->contains(
                fn ($voice) => filled($voice->source_voice_id) && filled($voice->source)
            );
        }

        if (!$this->profile->exists) {
            return false;
        }

        return $this->profile->voices()
            ->whereNotNull('source_voice_id')
            ->where('source_voice_id', '!=', '')
            ->whereNotNull('source')
            ->where('source', '!=', '')
            ->exists();
    }
}
