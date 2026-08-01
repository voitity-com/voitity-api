<?php

namespace Tests\Feature\Insights;

use App\Enums\ChatStatus;
use App\Enums\ProfileInsightEventType;
use App\Models\Chat;
use App\Models\Message;
use App\Models\Profile;
use App\Models\User;
use App\Services\Insights\ProfileInteractionRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProfileInteractionRecorderTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_records_one_snapshotted_product_impression_per_answer_and_product(): void
    {
        $profile = Profile::factory()->for(User::factory())->create();
        $chat = Chat::query()->create([
            'profile_id' => $profile->id,
            'status' => ChatStatus::Open,
            'started_at' => now(),
            'last_activity_at' => now(),
            'visitor_id_hash' => 'visitor-hash',
        ]);
        $answer = Message::query()->create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Recommended product.',
            'type' => 'answer',
            'source' => 'openai',
        ]);
        $publicId = (string) Str::uuid();
        $payload = [[
            'id' => 42,
            'public_id' => $publicId,
            'name' => 'Recorded product',
            'image_url' => 'https://example.com/product.jpg',
            'destination_type' => 'whatsapp',
            'status' => 'published',
        ]];
        $recorder = app(ProfileInteractionRecorder::class);

        $recorder->recordShownProducts($profile, $answer, $payload);
        $recorder->recordShownProducts($profile, $answer, $payload);

        $this->assertDatabaseCount('profile_interaction_events', 1);
        $this->assertDatabaseHas('profile_interaction_events', [
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'visitor_id_hash' => 'visitor-hash',
            'event_type' => ProfileInsightEventType::ProductShown->value,
            'subject_id' => '42',
            'subject_public_id' => $publicId,
            'subject_name' => 'Recorded product',
            'subject_status' => 'published',
            'destination_type' => 'whatsapp',
            'surface' => 'chat_answer',
        ]);
    }
}
