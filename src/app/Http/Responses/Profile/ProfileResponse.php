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
            'data' => $this->profile->data,
            'created_at' => $this->profile->created_at?->toJSON(),
            'updated_at' => $this->profile->updated_at?->toJSON(),
        ];
    }
}
