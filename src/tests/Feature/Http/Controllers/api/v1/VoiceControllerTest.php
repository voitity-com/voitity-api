<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Classes\VoiceService\VoiceClient;
use App\Classes\VoiceService\VoiceClientAddedSample;
use App\Classes\VoiceService\VoiceClientClonedVoice;
use App\Classes\VoiceService\VoiceClientGeneratedAudio;
use App\Classes\VoiceService\VoiceManager;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\User;
use App\Models\Voice;
use App\Models\VoiceSample;
use Illuminate\Support\Facades\Hash;
use Mockery;

class VoiceControllerTest extends TestAPI
{
    /**
     * Voice api endpoint
     */
    const ENDPOINT_VOICE = '/api/voice';

    const ENDPOINT_VOICE_TEST = '/api/voice/test';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_store_fails_with_invalid_fields()
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken())
            ->postJson(self::ENDPOINT_VOICE, [
                'name' => '', // empty
            ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'language_code']);
    }

    public function test_unauthorized_user_can_not_create_profile()
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->faker->word())
            ->json('POST', self::ENDPOINT_VOICE, []);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_can_create_a_voice()
    {
        $token = $this->getToken();
        $user = User::where('email', 'voitity@gmail.com')->first();
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
            'active' => true,
        ]);

        $voice_data = [
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'language_code' => 'es',
            'profile_id' => $profile->id,
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_VOICE, $voice_data);

        $response->assertJsonPath('message', 'Voice created successfully.');
        $response->assertStatus(200);

        $response_content = json_decode($response->getContent());

        $new_voice = Voice::find($response_content->data->id);
        $this->assertEquals($voice_data['name'], $new_voice->name);
        $this->assertEquals($voice_data['description'], $new_voice->description);
        $this->assertEquals($voice_data['language_code'], $new_voice->language_code);
        $this->assertTrue((bool) $new_voice->active);
        $this->assertEquals($profile->id, $new_voice->profile_id);
    }

    public function test_store_voice_returns_existing_voice_for_profile_that_already_has_one()
    {
        $token = $this->getToken();
        $user = User::where('email', 'voitity@gmail.com')->first();

        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
            'active' => true,
        ]);

        $voice = Voice::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'language_code' => 'es',
        ]);

        $voice_data = [
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'language_code' => 'es',
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_VOICE, array_merge($voice_data, [
                'profile_id' => $profile->id,
            ]));

        $response->assertJsonPath('message', 'Voice already exists for profile.');
        $response->assertJsonPath('data.id', $voice->id);
        $response->assertStatus(200);
        $this->assertSame(1, Voice::where('profile_id', $profile->id)->where('active', true)->count());
        $voice->refresh();
        $this->assertEquals($voice_data['name'], $voice->name);
        $this->assertEquals($voice_data['description'], $voice->description);
        $this->assertEquals($voice_data['language_code'], $voice->language_code);
    }

    public function test_admin_can_update_existing_voice_for_another_users_profile_via_store()
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('test123'),
        ]);
        $owner = User::factory()->create();
        $profile = $this->createProfileForUser($owner);
        $this->createActiveSubscriptionFor($owner);
        $voice = Voice::factory()->create([
            'user_id' => $owner->id,
            'profile_id' => $profile->id,
            'name' => 'Old voice',
            'description' => 'Old description',
            'language_code' => 'es',
            'active' => true,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($admin->email, 'test123'))
            ->postJson(self::ENDPOINT_VOICE, [
                'name' => 'Admin saved voice',
                'description' => 'Admin saved description',
                'language_code' => 'en',
                'profile_id' => $profile->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Voice already exists for profile.');
        $response->assertJsonPath('data.id', $voice->id);
        $response->assertJsonPath('data.name', 'Admin saved voice');
        $response->assertJsonPath('data.description', 'Admin saved description');
        $response->assertJsonPath('data.language_code', 'en');

        $voice->refresh();
        $this->assertEquals($owner->id, $voice->user_id);
        $this->assertEquals('Admin saved voice', $voice->name);
        $this->assertEquals('Admin saved description', $voice->description);
        $this->assertEquals('en', $voice->language_code);
    }

    public function test_admin_can_create_voice_for_another_users_profile()
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('test123'),
        ]);
        $owner = User::factory()->create();
        $profile = $this->createProfileForUser($owner);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($admin->email, 'test123'))
            ->postJson(self::ENDPOINT_VOICE, [
                'name' => 'Admin created voice',
                'description' => 'Admin created description',
                'language_code' => 'en',
                'profile_id' => $profile->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Voice created successfully.');
        $response->assertJsonPath('data.profile_id', $profile->id);
        $response->assertJsonPath('data.user_id', $owner->id);
        $response->assertJsonPath('data.language_code', 'en');
    }

    public function test_store_fails_with_unsupported_language_code()
    {
        $token = $this->getToken();
        $user = User::where('email', 'voitity@gmail.com')->first();
        $profile = $this->createProfileForUser($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_VOICE, [
                'name' => 'Unsupported language voice',
                'description' => 'Language should be validated',
                'language_code' => 'fr',
                'profile_id' => $profile->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['language_code']);
    }

    public function test_user_can_update_his_voice()
    {
        $user = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);
        $profile = $this->createProfileForUser($user);
        $voice = Voice::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'name' => 'Old voice',
            'description' => 'Old description',
            'language_code' => 'es',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->patchJson(self::ENDPOINT_VOICE.'/'.$voice->id, [
                'name' => 'Updated voice',
                'description' => 'Updated description',
                'language_code' => 'en',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Voice updated successfully.');
        $response->assertJsonPath('data.id', $voice->id);
        $response->assertJsonPath('data.name', 'Updated voice');
        $response->assertJsonPath('data.description', 'Updated description');
        $response->assertJsonPath('data.language_code', 'en');

        $voice->refresh();
        $this->assertEquals('Updated voice', $voice->name);
        $this->assertEquals('Updated description', $voice->description);
        $this->assertEquals('en', $voice->language_code);
    }

    public function test_user_can_not_update_another_users_voice()
    {
        $requestUser = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);
        $owner = User::factory()->create();
        $profile = $this->createProfileForUser($owner);
        $this->createActiveSubscriptionFor($owner);
        $voice = Voice::factory()->create([
            'user_id' => $owner->id,
            'profile_id' => $profile->id,
            'name' => 'Owner voice',
            'description' => 'Owner description',
            'language_code' => 'es',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($requestUser->email, 'test123'))
            ->patchJson(self::ENDPOINT_VOICE.'/'.$voice->id, [
                'name' => 'Unauthorized update',
                'description' => 'Should not persist',
                'language_code' => 'en',
            ]);

        $response->assertStatus(403);
        $voice->refresh();
        $this->assertEquals('Owner voice', $voice->name);
        $this->assertEquals('Owner description', $voice->description);
        $this->assertEquals('es', $voice->language_code);
    }

    public function test_user_can_create_voice_for_second_profile_when_another_profile_has_active_voice()
    {
        $token = $this->getToken();
        $user = User::where('email', 'voitity@gmail.com')->first();
        $firstProfile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
            'active' => true,
        ]);
        $secondProfile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'female',
            'personality' => $this->faker->text(100),
            'active' => true,
        ]);

        Voice::create([
            'user_id' => $user->id,
            'profile_id' => $firstProfile->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'language_code' => 'es',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_VOICE, [
                'name' => $this->faker->name,
                'description' => $this->faker->text(200),
                'language_code' => 'es',
                'profile_id' => $secondProfile->id,
            ]);

        $response->assertJsonPath('message', 'Voice created successfully.');
        $response->assertJsonPath('data.profile_id', $secondProfile->id);
        $response->assertStatus(200);
        $this->assertSame(2, Voice::where('user_id', $user->id)->where('active', true)->count());
    }

    public function test_test_voice_fails_with_invalid_fields()
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken())
            ->postJson(self::ENDPOINT_VOICE_TEST, [
                'profile_id' => '',
                'text' => '',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['profile_id', 'text']);
    }

    public function test_unauthorized_user_can_not_test_voice()
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->faker->word())
            ->postJson(self::ENDPOINT_VOICE_TEST, []);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_without_voice_use_ability_can_not_test_voice()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('test-token', ['voice:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_VOICE_TEST, [
                'profile_id' => 1,
                'text' => 'Hola mundo',
            ]);

        $response->assertStatus(403);
    }

    public function test_user_can_not_test_voice_for_profile_he_does_not_own()
    {
        $user = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);
        $otherUser = User::factory()->create();
        $profile = $this->createProfileForUser($otherUser);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->postJson(self::ENDPOINT_VOICE_TEST, [
                'profile_id' => $profile->id,
                'text' => 'Hola mundo',
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Profile not found.');
    }

    public function test_admin_can_test_voice_for_profile_he_does_not_own()
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('test123'),
        ]);
        $owner = User::factory()->create();
        $profile = $this->createProfileForUser($owner);
        $this->createActiveSubscriptionFor($owner);
        $voice = Voice::factory()->create([
            'user_id' => $owner->id,
            'profile_id' => $profile->id,
            'source' => 'elevenlabs',
            'source_voice_id' => 'provider-voice-id',
            'active' => true,
        ]);

        $voiceClient = new class($voice) implements VoiceClient
        {
            public function __construct(private readonly Voice $voice) {}

            public function cloneVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientClonedVoice
            {
                throw new \RuntimeException('Not used in this test.');
            }

            public function addVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientAddedSample
            {
                throw new \RuntimeException('Not used in this test.');
            }

            public function generateAudio(Voice $voice, string $text): VoiceClientGeneratedAudio
            {
                if ($voice->id !== $this->voice->id) {
                    throw new \RuntimeException('Unexpected voice generation input.');
                }

                return new VoiceClientGeneratedAudio(
                    $voice,
                    $text,
                    'http://localhost/storage/generated/admin-test.mp3',
                    'base64-audio',
                    'mp3',
                    2.4,
                    'completed',
                    ['provider' => 'fake']
                );
            }
        };

        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')
            ->once()
            ->with('elevenlabs')
            ->andReturn($voiceClient);
        $this->app->instance(VoiceManager::class, $voiceManager);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($admin->email, 'test123'))
            ->postJson(self::ENDPOINT_VOICE_TEST, [
                'profile_id' => $profile->id,
                'text' => 'Hola mundo',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Voice audio generated successfully.');
        $response->assertJsonPath('data.voice_id', $voice->id);
        $response->assertJsonPath('data.profile_id', $profile->id);
        $response->assertJsonPath('data.audio_url', 'http://localhost/storage/generated/admin-test.mp3');
    }

    public function test_api_user_can_test_voice_for_profile_he_does_not_own()
    {
        $apiUser = User::factory()->create(['role' => 'api']);
        $owner = User::factory()->create();
        $profile = $this->createProfileForUser($owner);
        $this->createActiveSubscriptionFor($owner);
        $voice = Voice::factory()->create([
            'user_id' => $owner->id,
            'profile_id' => $profile->id,
            'source' => 'elevenlabs',
            'source_voice_id' => 'provider-voice-id',
            'active' => true,
        ]);

        $voiceClient = new class($voice) implements VoiceClient
        {
            public function __construct(private readonly Voice $voice) {}

            public function cloneVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientClonedVoice
            {
                throw new \RuntimeException('Not used in this test.');
            }

            public function addVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientAddedSample
            {
                throw new \RuntimeException('Not used in this test.');
            }

            public function generateAudio(Voice $voice, string $text): VoiceClientGeneratedAudio
            {
                if ($voice->id !== $this->voice->id) {
                    throw new \RuntimeException('Unexpected voice generation input.');
                }

                return new VoiceClientGeneratedAudio(
                    $voice,
                    $text,
                    'http://localhost/storage/generated/api-test.mp3',
                    'base64-audio',
                    'mp3',
                    2.4,
                    'completed',
                    ['provider' => 'fake']
                );
            }
        };

        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')
            ->once()
            ->with('elevenlabs')
            ->andReturn($voiceClient);
        $this->app->instance(VoiceManager::class, $voiceManager);

        $token = $apiUser->createToken('test-token', ['voice:use'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_VOICE_TEST, [
                'profile_id' => $profile->id,
                'text' => 'Hola mundo',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Voice audio generated successfully.');
        $response->assertJsonPath('data.voice_id', $voice->id);
        $response->assertJsonPath('data.profile_id', $profile->id);
        $response->assertJsonPath('data.audio_url', 'http://localhost/storage/generated/api-test.mp3');
    }

    public function test_user_can_not_test_voice_when_profile_has_no_active_voice()
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('test123'),
        ]);
        $profile = $this->createProfileForUser($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->postJson(self::ENDPOINT_VOICE_TEST, [
                'profile_id' => $profile->id,
                'text' => 'Hola mundo',
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Voice not found.');
    }

    public function test_user_can_test_voice()
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('test123'),
        ]);
        $profile = $this->createProfileForUser($user);
        $this->createActiveSubscriptionFor($user);
        $voice = Voice::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source' => 'elevenlabs',
            'source_voice_id' => 'provider-voice-id',
            'active' => true,
        ]);
        $text = 'Hola mundo';

        $voiceClient = new class($voice, $text) implements VoiceClient
        {
            public function __construct(
                private readonly Voice $voice,
                private readonly string $expectedText
            ) {}

            public function cloneVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientClonedVoice
            {
                throw new \RuntimeException('Not used in this test.');
            }

            public function addVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientAddedSample
            {
                throw new \RuntimeException('Not used in this test.');
            }

            public function generateAudio(Voice $voice, string $text): VoiceClientGeneratedAudio
            {
                if ($voice->id !== $this->voice->id || $text !== $this->expectedText) {
                    throw new \RuntimeException('Unexpected voice generation input.');
                }

                return new VoiceClientGeneratedAudio(
                    $voice,
                    $text,
                    'http://localhost/storage/generated/test.mp3',
                    'base64-audio',
                    'mp3',
                    2.4,
                    'completed',
                    ['provider' => 'fake']
                );
            }
        };

        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')
            ->once()
            ->with('elevenlabs')
            ->andReturn($voiceClient);
        $this->app->instance(VoiceManager::class, $voiceManager);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->postJson(self::ENDPOINT_VOICE_TEST, [
                'profile_id' => $profile->id,
                'text' => $text,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Voice audio generated successfully.');
        $response->assertJsonPath('data.voice_id', $voice->id);
        $response->assertJsonPath('data.profile_id', $profile->id);
        $response->assertJsonPath('data.text', $text);
        $response->assertJsonPath('data.audio_url', 'http://localhost/storage/generated/test.mp3');
        $response->assertJsonPath('data.audio_content', 'base64-audio');
        $response->assertJsonPath('data.status', 'completed');
    }

    public function test_test_voice_returns_bad_gateway_when_generation_fails()
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('test123'),
        ]);
        $profile = $this->createProfileForUser($user);
        $this->createActiveSubscriptionFor($user);
        $voice = Voice::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source' => 'elevenlabs',
            'source_voice_id' => 'provider-voice-id',
            'active' => true,
        ]);

        $voiceClient = new class implements VoiceClient
        {
            public function cloneVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientClonedVoice
            {
                throw new \RuntimeException('Not used in this test.');
            }

            public function addVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientAddedSample
            {
                throw new \RuntimeException('Not used in this test.');
            }

            public function generateAudio(Voice $voice, string $text): VoiceClientGeneratedAudio
            {
                return new VoiceClientGeneratedAudio(
                    $voice,
                    $text,
                    null,
                    null,
                    'mp3',
                    null,
                    'failed',
                    ['error' => 'provider error']
                );
            }
        };

        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')
            ->once()
            ->with('elevenlabs')
            ->andReturn($voiceClient);
        $this->app->instance(VoiceManager::class, $voiceManager);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->postJson(self::ENDPOINT_VOICE_TEST, [
                'profile_id' => $profile->id,
                'text' => 'Hola mundo',
            ]);

        $response->assertStatus(502);
        $response->assertJsonPath('message', 'Voice audio generation failed.');
        $response->assertJsonPath('data.voice_id', $voice->id);
        $response->assertJsonPath('data.status', 'failed');
        $response->assertJsonPath('data.metadata.error', 'provider error');
    }

    private function createProfileForUser(User $user): Profile
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
