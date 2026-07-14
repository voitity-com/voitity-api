<?php

namespace Database\Factories;

use App\Models\Profile;
use App\Models\ProfileConversationMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProfileConversationMessage>
 */
class ProfileConversationMessageFactory extends Factory
{
    protected $model = ProfileConversationMessage::class;

    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'type' => ProfileConversationMessage::TYPE_INITIAL,
            'text' => $this->faker->sentence(),
            'audio_url' => null,
            'audio_path' => null,
            'audio_disk' => null,
            'audio_source' => null,
            'audio_format' => null,
            'voice_id' => null,
            'status' => ProfileConversationMessage::STATUS_READY,
            'text_hash' => null,
            'metadata' => [],
        ];
    }
}
