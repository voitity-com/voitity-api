<?php

namespace App\Http\Responses\Profile;

use App\Models\Profile;
use App\Models\Voice;

class ProfileResponse
{
    public function __construct(private readonly Profile $profile) {}

    public function toArray(): array
    {
        $activeVoice = $this->activeVoice();

        return [
            'id' => $this->profile->id,
            'user_id' => $this->profile->user_id,
            'alias' => $this->profile->alias,
            'name' => $this->profile->name,
            'description' => $this->profile->description,
            'genre' => $this->profile->genre,
            'personality' => $this->profile->personality,
            'active' => (bool) $this->profile->active,
            'status' => $this->profile->status?->value,
            'voice' => $this->hasConfiguredVoice($activeVoice),
            'voice_id' => $activeVoice?->id,
            'data' => $this->profile->data,
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

    private function hasConfiguredVoice(?Voice $voice): bool
    {
        if (! $voice) {
            return false;
        }

        return filled($voice->source_voice_id)
            && filled($voice->source);
    }
}
