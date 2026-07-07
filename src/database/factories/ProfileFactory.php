<?php

namespace Database\Factories;

use App\Enums\ProfileStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Profile>
 */
class ProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'alias' => $this->faker->unique()->slug(2),
            'name' => $this->faker->name(),
            'description' => $this->faker->paragraph(),
            'genre' => 'na',
            'personality' => $this->faker->sentence(),
            'profession_key' => 'custom',
            'profession_template_version' => '2026-07',
            'active' => true,
            'status' => ProfileStatus::Draft,
            'data' => [
                'me' => new \stdClass,
                'work' => [],
                'projects' => [],
            ],
            'networks' => new \stdClass,
        ];
    }
}
