<?php

namespace App\Http\Responses\User;

use App\Models\User;

class UserResponse
{
    public function __construct(private readonly User $user)
    {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->user->id,
            'name' => $this->user->name,
            'first_name' => $this->user->first_name,
            'last_name' => $this->user->last_name,
            'email' => $this->user->email,
            'role' => $this->user->role,
            'avatar' => $this->user->avatar,
            'provider' => $this->user->provider,
            'email_verified_at' => $this->user->email_verified_at?->toJSON(),
            'google_verified_at' => $this->user->google_verified_at?->toJSON(),
            'created_at' => $this->user->created_at?->toJSON(),
            'updated_at' => $this->user->updated_at?->toJSON(),
        ];
    }
}
