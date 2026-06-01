<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AiImage>
 */
class AiImageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => 1,
            'profile_id' => null,
            'source_id' => $this->faker->uuid(),
            'source' => 'runway',
            'status' => 'pending',
            'file' => 'images/' . $this->faker->uuid() . '.png',
        ];
    }
}
