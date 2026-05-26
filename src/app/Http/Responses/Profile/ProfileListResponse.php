<?php

namespace App\Http\Responses\Profile;

use Illuminate\Support\Collection;

class ProfileListResponse
{
    public function __construct(private readonly Collection $profiles)
    {
    }

    public function toArray(): array
    {
        return [
            'profiles' => $this->profiles
                ->map(fn ($profile) => (new ProfileResponse($profile))->toArray())
                ->values()
                ->all(),
            'total' => $this->profiles->count(),
        ];
    }
}
