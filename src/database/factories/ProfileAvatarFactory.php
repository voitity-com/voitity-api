<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProfileAvatar>
 */
class ProfileAvatarFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => 1,
            'profile_id' => 1,
            'aiimage_id' => null,
            'ai_video_id' => null,
            'file' => null,
            'status' => 'active',
        ];
    }
}
