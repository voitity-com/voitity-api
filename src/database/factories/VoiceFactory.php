<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Voice>
 */
class VoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => 1,
            'name' => $this->faker->words(2, true),
            'description' => $this->faker->sentence(),
            'language_code' => 'es',
            'source_voice_id' => $this->faker->bothify('?#?#?#?#?#?#?#?#'),
            'source' => $this->faker->randomElement(['external', 'internal', 'upload']),
            'is_verified' => $this->faker->boolean(20),
            'verified_at' => null,
            'active' => true,
        ];
    }
}
