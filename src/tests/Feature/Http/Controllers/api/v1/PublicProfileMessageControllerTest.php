<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Classes\ChatAIService\AudioMessageInspector;
use App\Classes\ChatAIService\ChatAIAnswer;
use App\Classes\ChatAIService\ChatAIClient;
use App\Classes\ChatAIService\ChatAITextFromAudio;
use App\Classes\PublicProfiles\PublicChatSession;
use App\Enums\ProfileStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Chat;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;

class PublicProfileMessageControllerTest extends TestAPI
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_visitor_can_create_and_continue_chat_with_encrypted_session_token(): void
    {
        $profile = $this->publicProfileWithSubscription();
        $client = Mockery::mock(ChatAIClient::class);
        $client->shouldReceive('getAnswer')
            ->twice()
            ->andReturn(
                new ChatAIAnswer('openai', 'First answer', 'success'),
                new ChatAIAnswer('openai', 'Second answer', 'success'),
            );
        $this->instance(ChatAIClient::class, $client);

        $first = $this->postJson(
            "/api/public/profiles/{$profile->id}/messages",
            ['message' => 'First question'],
        );

        $first->assertOk()
            ->assertJsonPath('data.text', 'First answer');
        $chatId = (int) $first->json('data.chat_id');
        $chatToken = (string) $first->json('data.chat_token');

        $this->assertGreaterThan(0, $chatId);
        $this->assertNotSame('', $chatToken);

        $second = $this->withHeader('X-Bigmelo-Chat-Token', $chatToken)
            ->postJson(
                "/api/public/profiles/{$profile->id}/messages",
                [
                    'message' => 'Second question',
                    'chat_id' => $chatId,
                ],
            );

        $second->assertOk()
            ->assertJsonPath('data.chat_id', $chatId)
            ->assertJsonPath('data.text', 'Second answer');
        $this->assertNotSame('', (string) $second->json('data.chat_token'));
        $this->assertDatabaseCount('chats', 1);
    }

    public function test_message_after_thirty_minutes_starts_new_chat_and_closes_previous_one(): void
    {
        config(['insights.chat_inactivity_minutes' => 30]);
        $profile = $this->publicProfileWithSubscription();
        $client = Mockery::mock(ChatAIClient::class);
        $client->shouldReceive('getAnswer')->twice()->andReturn(
            new ChatAIAnswer('openai', 'First answer', 'success'),
            new ChatAIAnswer('openai', 'New chat answer', 'success'),
        );
        $this->instance(ChatAIClient::class, $client);
        $first = $this->postJson("/api/public/profiles/{$profile->id}/messages", ['message' => 'First question']);
        $chatId = (int) $first->json('data.chat_id');
        $token = (string) $first->json('data.chat_token');
        Chat::query()->whereKey($chatId)->update(['last_activity_at' => now()->subMinutes(31)]);

        $second = $this->withHeader('X-Bigmelo-Chat-Token', $token)->postJson(
            "/api/public/profiles/{$profile->id}/messages",
            ['message' => 'Question after inactivity', 'chat_id' => $chatId],
        );

        $second->assertOk();
        $this->assertNotSame($chatId, (int) $second->json('data.chat_id'));
        $this->assertSame('closed', Chat::findOrFail($chatId)->status->value);
        $this->assertDatabaseCount('chats', 2);
    }

    public function test_missing_or_tampered_token_starts_a_new_chat_without_continuing_target_chat(): void
    {
        $profile = $this->publicProfileWithSubscription();
        $chat = Chat::create(['profile_id' => $profile->id]);
        $token = app(PublicChatSession::class)->issue($profile, $chat);
        $client = Mockery::mock(ChatAIClient::class);
        $client->shouldReceive('getAnswer')->twice()->andReturn(
            new ChatAIAnswer('openai', 'Fresh answer one', 'success'),
            new ChatAIAnswer('openai', 'Fresh answer two', 'success'),
        );
        $this->instance(ChatAIClient::class, $client);

        $missingTokenResponse = $this->postJson(
            "/api/public/profiles/{$profile->id}/messages",
            ['message' => 'Missing token', 'chat_id' => $chat->id],
        )->assertOk();

        $tamperedTokenResponse = $this->withHeader('X-Bigmelo-Chat-Token', $token.'tampered')
            ->postJson(
                "/api/public/profiles/{$profile->id}/messages",
                ['message' => 'Tampered token', 'chat_id' => $chat->id],
            )
            ->assertOk();

        $this->assertNotSame($chat->id, (int) $missingTokenResponse->json('data.chat_id'));
        $this->assertNotSame($chat->id, (int) $tamperedTokenResponse->json('data.chat_id'));
        $this->assertSame(0, $chat->messages()->count());
    }

    public function test_chat_session_token_for_another_profile_starts_a_new_chat_on_target_profile(): void
    {
        $profile = $this->publicProfileWithSubscription();
        $otherProfile = $this->publicProfileWithSubscription();
        $chat = Chat::create(['profile_id' => $otherProfile->id]);
        $token = app(PublicChatSession::class)->issue($otherProfile, $chat);
        $client = Mockery::mock(ChatAIClient::class);
        $client->shouldReceive('getAnswer')->once()->andReturn(new ChatAIAnswer('openai', 'Safe fresh answer', 'success'));
        $this->instance(ChatAIClient::class, $client);

        $response = $this->withHeader('X-Bigmelo-Chat-Token', $token)
            ->postJson(
                "/api/public/profiles/{$profile->id}/messages",
                ['message' => 'Cross-profile attempt', 'chat_id' => $chat->id],
            )
            ->assertOk();

        $this->assertNotSame($chat->id, (int) $response->json('data.chat_id'));
        $this->assertSame($profile->id, Chat::findOrFail((int) $response->json('data.chat_id'))->profile_id);
    }

    public function test_visitor_can_not_message_non_public_profile(): void
    {
        $profile = Profile::factory()
            ->for(User::factory())
            ->create([
                'active' => false,
                'status' => ProfileStatus::Published,
            ]);

        $this->postJson(
            "/api/public/profiles/{$profile->id}/messages",
            ['message' => 'Private profile attempt'],
        )->assertNotFound()
            ->assertJsonPath('message', 'Profile not found.');
    }

    public function test_visitor_can_send_audio_and_receives_chat_session_token(): void
    {
        Storage::fake('public');
        $profile = $this->publicProfileWithSubscription();
        $inspector = Mockery::mock(AudioMessageInspector::class);
        $inspector->shouldReceive('durationSeconds')->once()->andReturn(4);
        $this->instance(AudioMessageInspector::class, $inspector);

        $client = Mockery::mock(ChatAIClient::class);
        $client->shouldReceive('getTextFromAudio')
            ->once()
            ->andReturn(new ChatAITextFromAudio(
                source: 'openai',
                audioPath: '/tmp/audio.webm',
                text: 'Public audio question',
                status: 'success',
                confidence: 0.9,
                detectedLanguage: 'en',
                duration: 4,
            ));
        $client->shouldReceive('getAnswer')
            ->once()
            ->andReturn(new ChatAIAnswer('openai', 'Public audio answer', 'success'));
        $this->instance(ChatAIClient::class, $client);

        $response = $this->post(
            "/api/public/profiles/{$profile->id}/messages/audio",
            [
                'audio' => UploadedFile::fake()
                    ->create('recording.webm', 128, 'audio/webm'),
            ],
            ['Accept' => 'application/json'],
        );

        $response->assertOk()
            ->assertJsonPath('data.request_text', 'Public audio question')
            ->assertJsonPath('data.text', 'Public audio answer');
        $this->assertNotSame('', (string) $response->json('data.chat_token'));
    }

    public function test_existing_authenticated_message_endpoint_remains_protected(): void
    {
        $profile = $this->publicProfileWithSubscription();

        $this->postJson(
            "/api/profile/{$profile->id}/messages",
            ['message' => 'No authentication'],
        )->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_authenticated_message_endpoint_keeps_its_existing_chat_not_found_contract(): void
    {
        $profile = $this->publicProfileWithSubscription();
        $otherProfile = $this->publicProfileWithSubscription();
        $otherChat = Chat::create(['profile_id' => $otherProfile->id]);
        $token = $profile->user->createToken(
            'protected-message-test',
            ['messages:write'],
        )->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(
                "/api/profile/{$profile->id}/messages",
                [
                    'message' => 'Missing protected chat',
                    'chat_id' => $otherChat->id,
                ],
            )
            ->assertNotFound()
            ->assertJsonPath('message', 'Chat not found.')
            ->assertJsonMissingPath('code');
    }

    private function publicProfileWithSubscription(): Profile
    {
        $owner = User::factory()->create();
        $profile = Profile::factory()
            ->for($owner)
            ->create([
                'active' => true,
                'status' => ProfileStatus::Published,
            ]);
        $subscription = Subscription::create([
            'user_id' => $owner->id,
            'plan' => SubscriptionPlan::Starter,
            'started_at' => now()->subDay(),
            'renews_at' => now()->addMonth(),
            'status' => SubscriptionStatus::First,
            'active' => true,
        ]);
        SubscriptionLimit::create([
            'subscription_id' => $subscription->id,
            'user_id' => $owner->id,
            'period_started_at' => $subscription->started_at,
            'period_renews_at' => $subscription->renews_at,
            'profiles_remaining' => 0,
            'avatar_images_remaining' => 1,
            'avatar_video_seconds_remaining' => 5,
            'voice_clones_remaining' => 1,
            'tts_characters_remaining' => 20000,
            'chat_messages_remaining' => 1000,
            'incoming_audio_messages_remaining' => 500,
            'incoming_audio_seconds_remaining' => 15000,
            'credits_remaining' => 1000,
        ]);

        return $profile;
    }
}
