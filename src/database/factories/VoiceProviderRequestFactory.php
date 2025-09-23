<?php

namespace Database\Factories;

use App\Models\Voice;
use App\Models\VoiceSample;
use App\Models\VoiceProviderRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VoiceProviderRequest>
 */
class VoiceProviderRequestFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = VoiceProviderRequest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'voice_id' => Voice::factory(),
            'voice_sample_id' => VoiceSample::factory(),
            'source' => $this->faker->randomElement(['elevenlabs', 'openai', 'aws-polly']),
            'request_url' => $this->faker->url(),
            'response' => json_encode([
                'status' => 'success',
                'message' => 'Voice sample processed successfully'
            ]),
            'status' => $this->faker->randomElement(['pending', 'processing', 'completed', 'failed']),
            'processed_at' => $this->faker->optional(0.7)->dateTimeBetween('-1 week', 'now'),
        ];
    }

    /**
     * Indicate that the request is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'processed_at' => null,
        ]);
    }

    /**
     * Indicate that the request is processing.
     */
    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
            'processed_at' => null,
        ]);
    }

    /**
     * Indicate that the request is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'processed_at' => now(),
        ]);
    }

    /**
     * Indicate that the request failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'processed_at' => now(),
        ]);
    }
}
