<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Classes\VoiceSampleFileManager;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionUsageType;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\User;
use App\Models\Voice;
use App\Models\VoiceProviderRequest;
use App\Models\VoiceSample;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;

class VoiceSampleControllerTest extends TestAPI
{
    const ENDPOINT_VOICESAMPLE = '/api/voice/voice-id/sample';

    const ENDPOINT_VOICESAMPLE_PROCESS = '/api/voice/voice-id/sample/voice-sample-id/process';

    protected function setUp(): void
    {
        parent::setUp();

        // Use fake storage to prevent actual file operations
        Storage::fake('local');

        /*
         * APPROACH 1 (Current): Mock VoiceSampleFileManager
         * - Prevents actual file creation
         * - Faster test execution
         * - Tests controller logic without file I/O
         * - Recommended for controller tests
         *
         * APPROACH 2 (Alternative): Real file operations with cleanup
         * - Replace Storage::fake('local') with Storage::fake()
         * - Remove VoiceSampleFileManager mocking in individual tests
         * - Files will be automatically cleaned up by Storage::fake()
         * - Better for integration testing but slower
         */
    }

    protected function tearDown(): void
    {
        // Clean up Mockery mocks
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Gen url for process voice sample endpoint
     */
    public function getProcessVoiceSampleUrl(int $voice_id = 1, int $voice_sample_id = 1): string
    {
        return str_replace(
            ['voice-id', 'voice-sample-id'],
            [(string) $voice_id, (string) $voice_sample_id],
            self::ENDPOINT_VOICESAMPLE_PROCESS
        );
    }

    public function test_store_fails_with_invalid_fields()
    {
        // Get token and create user first
        $token = $this->getToken();
        $user = \App\Models\User::where('email', 'voitity@gmail.com')->first();

        $voice = Voice::factory()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(str_replace('voice-id', (string) $voice->id, self::ENDPOINT_VOICESAMPLE), [
                'file' => '', // empty
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file']);
    }

    public function test_unauthorized_user_can_not_create_profile()
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->faker->word())
            ->postJson(str_replace('voice-id', '1', self::ENDPOINT_VOICESAMPLE), []);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_can_not_create_voice_sample_if_he_is_not_voice_owner()
    {
        // Mock the VoiceSampleFileManager (even though this test should fail before using it)
        $mockFileManager = Mockery::mock(VoiceSampleFileManager::class);
        $this->app->instance(VoiceSampleFileManager::class, $mockFileManager);

        $owner = User::factory()->create();
        $requestUser = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);
        $voice = Voice::factory()->create(['user_id' => $owner->id]);
        $data = [
            'file' => UploadedFile::fake()->create('sample.mp3', 100, 'audio/mpeg'),
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($requestUser->email, 'test123'))
            ->postJson(str_replace('voice-id', (string) $voice->id, self::ENDPOINT_VOICESAMPLE), $data);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Voice not found.');
    }

    public function test_admin_can_create_voice_sample_for_another_users_voice()
    {
        $mockFileManager = Mockery::mock(VoiceSampleFileManager::class);
        $mockFileManager->shouldReceive('processSampleFile')
            ->once()
            ->andReturn(true);
        $mockFileManager->shouldReceive('getFileName')
            ->once()
            ->andReturn('admin-sample.mp3');
        $mockFileManager->shouldReceive('getFileDuration')
            ->once()
            ->andReturn(90);

        $this->app->instance(VoiceSampleFileManager::class, $mockFileManager);

        $token = $this->getToken();
        $owner = User::factory()->create();
        $voice = Voice::factory()->create(['user_id' => $owner->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(str_replace('voice-id', (string) $voice->id, self::ENDPOINT_VOICESAMPLE), [
                'file' => UploadedFile::fake()->create('sample.mp3', 100, 'audio/mpeg'),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Voice sample created successfully.');
    }

    public function test_user_can_create_voice_sample()
    {
        // Mock the VoiceSampleFileManager to avoid actual file operations
        $mockFileManager = Mockery::mock(VoiceSampleFileManager::class);
        $mockFileManager->shouldReceive('processSampleFile')
            ->once()
            ->andReturn(true);
        $mockFileManager->shouldReceive('getFileName')
            ->once()
            ->andReturn('test-uuid-123.mp3');
        $mockFileManager->shouldReceive('getFileDuration')
            ->once()
            ->andReturn(120);

        // Bind the mock to the service container
        $this->app->instance(VoiceSampleFileManager::class, $mockFileManager);

        // Get token and create user first
        $token = $this->getToken();
        $user = \App\Models\User::where('email', 'voitity@gmail.com')->first();

        $voice = Voice::factory()->create(['user_id' => $user->id]);
        $data = [
            'file' => UploadedFile::fake()->create('sample.mp3', 100, 'audio/mpeg'),
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(str_replace('voice-id', (string) $voice->id, self::ENDPOINT_VOICESAMPLE), $data);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Voice sample created successfully.');
        $response_content = json_decode($response->getContent());

        $new_sample = VoiceSample::find($response_content->data->id);
        $this->assertEquals('mp3', pathinfo($new_sample->file, PATHINFO_EXTENSION));
        $this->assertTrue((bool) $new_sample->active);
    }

    public function test_user_can_create_large_voice_sample()
    {
        $mockFileManager = Mockery::mock(VoiceSampleFileManager::class);
        $mockFileManager->shouldReceive('processSampleFile')
            ->once()
            ->andReturn(true);
        $mockFileManager->shouldReceive('getFileName')
            ->once()
            ->andReturn('large-sample.mp3');
        $mockFileManager->shouldReceive('getFileDuration')
            ->once()
            ->andReturn(180);

        $this->app->instance(VoiceSampleFileManager::class, $mockFileManager);

        $token = $this->getToken();
        $user = \App\Models\User::where('email', 'voitity@gmail.com')->first();
        $voice = Voice::factory()->create(['user_id' => $user->id]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(str_replace('voice-id', (string) $voice->id, self::ENDPOINT_VOICESAMPLE), [
                'file' => UploadedFile::fake()->create('sample.mp3', 12288, 'audio/mpeg'),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Voice sample created successfully.');
    }

    public function test_user_can_update_voice_language_when_creating_sample()
    {
        $mockFileManager = Mockery::mock(VoiceSampleFileManager::class);
        $mockFileManager->shouldReceive('processSampleFile')->andReturn(true);
        $mockFileManager->shouldReceive('getFileName')->andReturn('sample.mp3');
        $mockFileManager->shouldReceive('getFileDuration')->andReturn(90);
        $this->app->instance(VoiceSampleFileManager::class, $mockFileManager);

        $token = $this->getToken();
        $user = \App\Models\User::where('email', 'voitity@gmail.com')->first();
        $voice = Voice::factory()->create(['user_id' => $user->id, 'language_code' => 'es']);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(str_replace('voice-id', (string) $voice->id, self::ENDPOINT_VOICESAMPLE), [
                'file' => UploadedFile::fake()->create('sample.mp3', 80, 'audio/mpeg'),
                'language_code' => 'en',
            ]);

        $response->assertStatus(200);
        $voice->refresh();
        $this->assertSame('en', $voice->language_code);
    }

    public function test_unauthorized_user_can_not_process_a_voice_sample()
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->faker->word())
            ->postJson($this->getProcessVoiceSampleUrl(1, 1));

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_profile_owner_can_process_a_voice_sample()
    {
        Event::fake();

        $user = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);
        $voiceUser = User::factory()->create();
        $this->createActiveSubscriptionFor($voiceUser);
        $profile = $this->profileForUser($user);
        $voice = Voice::factory()->create([
            'user_id' => $voiceUser->id,
            'profile_id' => $profile->id,
        ]);
        $voiceSample = VoiceSample::factory()->create([
            'voice_id' => $voice->id,
            'file' => 'test-uuid-123.mp3',
            'duration' => 120,
            'active' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->postJson($this->getProcessVoiceSampleUrl($voice->id, $voiceSample->id));

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Voice sample is processing successfully.');

        Event::assertDispatched(\App\Events\Voices\VoiceSampleAdded::class, function ($event) use ($voice, $voiceSample) {
            return $event->voice->id === $voice->id && $event->voiceSample->id === $voiceSample->id;
        });
        $providerRequestId = (int) $response->json('data.id');
        $this->assertDatabaseHas('subscription_uses', [
            'user_id' => $voiceUser->id,
            'profile_id' => $profile->id,
            'usage_type' => SubscriptionUsageType::VoiceCloned->value,
            'voice_clones_used' => 1,
            'idempotency_key' => "voice-clone:provider-request:{$providerRequestId}",
            'status' => \App\Models\SubscriptionUse::STATUS_RESERVED,
        ]);
        $this->assertSame(0, (int) $voiceUser->subscriptions()->where('active', true)->firstOrFail()->limit()->firstOrFail()->voice_clones_remaining);
    }

    public function test_user_can_not_process_a_voice_sample_for_another_users_profile()
    {
        $owner = User::factory()->create();
        $requestUser = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);
        $profile = $this->profileForUser($owner);
        $voice = Voice::factory()->create([
            'user_id' => $owner->id,
            'profile_id' => $profile->id,
        ]);
        $voiceSample = VoiceSample::factory()->create([
            'voice_id' => $voice->id,
            'file' => 'test-uuid-123.mp3',
            'duration' => 120,
            'active' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($requestUser->email, 'test123'))
            ->postJson($this->getProcessVoiceSampleUrl($voice->id, $voiceSample->id));

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Voice not found.');
        $this->assertDatabaseMissing('voice_provider_requests', [
            'voice_id' => $voice->id,
            'voice_sample_id' => $voiceSample->id,
        ]);
    }

    public function test_user_can_not_process_voice_sample_shorter_than_provider_minimum(): void
    {
        Event::fake();
        config()->set('voice.drivers.elevenlabs.min_clone_sample_duration_seconds', 5);

        $user = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);
        $this->createActiveSubscriptionFor($user);
        $voice = Voice::factory()->create([
            'user_id' => $user->id,
            'source_voice_id' => null,
        ]);
        $voiceSample = VoiceSample::factory()->create([
            'voice_id' => $voice->id,
            'file' => 'short-sample.mp3',
            'duration' => 3,
            'active' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->postJson($this->getProcessVoiceSampleUrl($voice->id, $voiceSample->id));

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Voice sample must be at least 5 seconds long.');
        $response->assertJsonPath('errors.duration.0', 'Voice sample must be at least 5 seconds long.');
        Event::assertNotDispatched(\App\Events\Voices\VoiceSampleAdded::class);
        $this->assertDatabaseMissing('voice_provider_requests', [
            'voice_id' => $voice->id,
            'voice_sample_id' => $voiceSample->id,
        ]);
        $this->assertDatabaseCount('subscription_uses', 0);
        $this->assertSame(1, (int) $user->subscriptions()->where('active', true)->firstOrFail()->limit()->firstOrFail()->voice_clones_remaining);
    }

    public function test_user_can_not_process_a_voice_sample_if_it_was_processed_previously()
    {
        // Get token and create user first
        $token = $this->getToken();
        $user = User::factory()->create();
        $this->createActiveSubscriptionFor($user);

        $voice = Voice::factory()->create(['user_id' => $user->id]);

        // Create a voice sample
        $voiceSample = VoiceSample::factory()->create([
            'voice_id' => $voice->id,
            'file' => 'test-uuid-123.mp3',
            'duration' => 120,
            'active' => true,
        ]);

        // Create a voice provider request to indicate it was already processed
        VoiceProviderRequest::factory()->create([
            'voice_id' => $voice->id,
            'voice_sample_id' => $voiceSample->id,
            'status' => VoiceProviderRequest::STATUS_COMPLETED,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson($this->getProcessVoiceSampleUrl($voice->id, $voiceSample->id));

        $response->assertStatus(400);
        $response->assertJsonPath('message', 'Voice sample was already processed.');
    }

    public function test_user_can_not_process_second_voice_clone_when_quota_is_used(): void
    {
        Event::fake();

        $user = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);
        $this->createActiveSubscriptionFor($user);
        $firstVoice = Voice::factory()->create(['user_id' => $user->id]);
        $secondVoice = Voice::factory()->create(['user_id' => $user->id]);
        $firstSample = VoiceSample::factory()->create([
            'voice_id' => $firstVoice->id,
            'file' => 'first-sample.mp3',
            'duration' => 120,
            'active' => true,
        ]);
        $secondSample = VoiceSample::factory()->create([
            'voice_id' => $secondVoice->id,
            'file' => 'second-sample.mp3',
            'duration' => 120,
            'active' => true,
        ]);
        $token = $this->getToken($user->email, 'test123');

        $firstResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson($this->getProcessVoiceSampleUrl($firstVoice->id, $firstSample->id));

        $firstResponse->assertStatus(200);
        $this->assertSame(0, (int) $user->subscriptions()->where('active', true)->firstOrFail()->limit()->firstOrFail()->voice_clones_remaining);

        $secondResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson($this->getProcessVoiceSampleUrl($secondVoice->id, $secondSample->id));

        $secondResponse->assertStatus(402);
        $secondResponse->assertJsonPath('message', 'Subscription limit exceeded.');
        $this->assertDatabaseMissing('voice_provider_requests', [
            'voice_id' => $secondVoice->id,
            'voice_sample_id' => $secondSample->id,
        ]);
    }

    public function test_admin_can_process_a_voice_sample()
    {
        Event::fake();

        // Get token and create user first
        $token = $this->getToken();
        $user = User::factory()->create();
        $this->createActiveSubscriptionFor($user);

        $voice = Voice::factory()->create(['user_id' => $user->id]);

        // Create a voice sample and mark it as already processed
        $voiceSample = VoiceSample::factory()->create([
            'voice_id' => $voice->id,
            'file' => 'test-uuid-123.mp3',
            'duration' => 120,
            'active' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson($this->getProcessVoiceSampleUrl($voice->id, $voiceSample->id));

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Voice sample is processing successfully.');
        $response_content = json_decode($response->getContent());

        $new_voice_provider_request = VoiceProviderRequest::find($response_content->data->id);
        $this->assertFalse(empty($new_voice_provider_request->status));

        Event::assertDispatched(\App\Events\Voices\VoiceSampleAdded::class, function ($event) use ($voiceSample) {
            return $event->voiceSample->id === $voiceSample->id;
        });
        $this->assertDatabaseHas('subscription_uses', [
            'user_id' => $user->id,
            'usage_type' => SubscriptionUsageType::VoiceCloned->value,
            'voice_clones_used' => 1,
            'idempotency_key' => "voice-clone:provider-request:{$new_voice_provider_request->id}",
            'status' => \App\Models\SubscriptionUse::STATUS_RESERVED,
        ]);
        $this->assertSame(0, (int) $user->subscriptions()->where('active', true)->firstOrFail()->limit()->firstOrFail()->voice_clones_remaining);
    }

    private function profileForUser(User $user): Profile
    {
        return Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
            'active' => true,
        ]);
    }

    private function createActiveSubscriptionFor(User $user): Subscription
    {
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan' => SubscriptionPlan::Starter,
            'started_at' => now()->subDay(),
            'renews_at' => now()->addMonth(),
            'status' => SubscriptionStatus::First,
            'active' => true,
        ]);

        SubscriptionLimit::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'period_started_at' => $subscription->started_at,
            'period_renews_at' => $subscription->renews_at,
            'profiles_remaining' => 1,
            'avatar_images_remaining' => 1,
            'avatar_video_seconds_remaining' => 5,
            'voice_clones_remaining' => 1,
            'tts_characters_remaining' => 10000,
            'chat_messages_remaining' => 1000,
            'credits_remaining' => 1000,
        ]);

        return $subscription;
    }
}
