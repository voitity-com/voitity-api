<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Classes\ChatAIService\ChatAIAnswer;
use App\Classes\ChatAIService\ChatAIClient;
use App\Classes\ChatAIService\ChatAITextFromAudio;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Chat;
use App\Models\Message;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;

class MessageControllerTest extends TestAPI
{
    private const ENDPOINT = '/api/profile';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_unauthorized_user_can_not_send_message(): void
    {
        $profile = Profile::create([
            'user_id' => User::factory()->create()->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->faker->word())
            ->postJson(self::ENDPOINT.'/'.$profile->id.'/messages', []);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_store_validates_message_field(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->postJson(self::ENDPOINT.'/'.$profile->id.'/messages', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['message']);
    }

    public function test_user_can_send_message_to_profile(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);
        $this->createActiveSubscriptionFor($user);

        $chat = Chat::create([
            'profile_id' => $profile->id,
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: 'Hello from AI!',
            status: 'success'
        );

        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->withArgs(function (Profile $boundProfile, string $message) use ($profile) {
                return $boundProfile->is($profile) && $message === 'Hi there!';
            })
            ->once()
            ->andReturn($chatAiAnswer);

        $this->instance(ChatAIClient::class, $chatAiClient);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->postJson(self::ENDPOINT.'/'.$profile->id.'/messages', [
                'message' => 'Hi there!',
                'chat_id' => $chat->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Message processed successfully.');
        $response->assertJsonPath('data.chat_id', $chat->id);
        $response->assertJsonPath('data.text', 'Hello from AI!');
        $response->assertJsonPath('data.audio_url', null);
        $response->assertJsonPath('data.source', 'openai');
        $response->assertJsonPath('data.data.chat_ai.answer', 'Hello from AI!');

        $this->assertDatabaseHas('messages', [
            'chat_id' => $chat->id,
            'profile_id' => $profile->id,
            'type' => 'question',
            'text' => 'Hi there!',
            'source' => 'api',
            'audio' => null,
        ]);

        $this->assertDatabaseHas('messages', [
            'chat_id' => $chat->id,
            'profile_id' => $profile->id,
            'type' => 'answer',
            'source' => 'openai',
            'audio' => null,
        ]);

        $this->assertSame(1, Message::where('chat_id', $chat->id)->where('type', 'answer')->count());
    }

    public function test_store_fails_when_chat_does_not_belong_to_profile(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $otherProfile = Profile::create([
            'user_id' => User::factory()->create()->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'female',
            'personality' => $this->faker->text(100),
        ]);

        $foreignChat = Chat::create([
            'profile_id' => $otherProfile->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->postJson(self::ENDPOINT.'/'.$profile->id.'/messages', [
                'message' => 'Hello!',
                'chat_id' => $foreignChat->id,
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Chat not found.');
    }

    public function test_user_can_not_send_message_to_foreign_profile(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $owner = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($owner);
        $token = $user->createToken('test-token', ['messages:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT.'/'.$profile->id.'/messages', [
                'message' => 'Hello!',
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Profile not found.');
        $this->assertSame(0, Message::count());
    }

    public function test_api_user_can_send_message_to_any_profile(): void
    {
        $apiUser = User::factory()->create(['role' => 'api']);
        $owner = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($owner);
        $this->createActiveSubscriptionFor($owner);
        $token = $apiUser->createToken('test-token', ['messages:write'])->plainTextToken;

        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->withArgs(function (Profile $boundProfile, string $message) use ($profile): bool {
                return $boundProfile->is($profile) && $message === 'Mensaje desde la web';
            })
            ->once()
            ->andReturn(new ChatAIAnswer(
                source: 'openai',
                answer: 'Respuesta para la web.',
                status: 'success'
            ));

        $this->instance(ChatAIClient::class, $chatAiClient);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT.'/'.$profile->id.'/messages', [
                'message' => 'Mensaje desde la web',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.text', 'Respuesta para la web.');
        $this->assertDatabaseHas('messages', [
            'profile_id' => $profile->id,
            'type' => 'question',
            'text' => 'Mensaje desde la web',
            'source' => 'api',
        ]);
    }

    public function test_user_can_send_message_and_new_chat_is_created_when_missing(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);
        $this->createActiveSubscriptionFor($user);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: 'Here is your answer.',
            status: 'success'
        );

        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->withArgs(function (Profile $boundProfile, string $message) use ($profile) {
                return $boundProfile->is($profile) && $message === 'Start conversation';
            })
            ->once()
            ->andReturn($chatAiAnswer);

        $this->instance(ChatAIClient::class, $chatAiClient);
        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->postJson(self::ENDPOINT.'/'.$profile->id.'/messages', [
                'message' => 'Start conversation',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.text', 'Here is your answer.');
        $chatId = $response->json('data.chat_id');

        $this->assertNotNull($chatId);
        $this->assertTrue(Chat::where('id', $chatId)->where('profile_id', $profile->id)->exists());

        $this->assertDatabaseHas('messages', [
            'chat_id' => $chatId,
            'profile_id' => $profile->id,
            'type' => 'question',
            'text' => 'Start conversation',
            'audio' => null,
        ]);

        $this->assertDatabaseHas('messages', [
            'chat_id' => $chatId,
            'profile_id' => $profile->id,
            'type' => 'answer',
            'audio' => null,
        ]);

        $this->assertSame(1, Message::where('chat_id', $chatId)->where('type', 'answer')->count());
    }

    public function test_store_returns_processing_pending_when_answer_not_ready(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);
        $this->createActiveSubscriptionFor($user);

        Event::fake([\App\Events\MessageStored::class]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->postJson(self::ENDPOINT.'/'.$profile->id.'/messages', [
                'message' => 'Queue this message',
            ]);

        $response->assertStatus(202);
        $response->assertJsonPath('data.text', null);
        $this->assertNotNull($response->json('data.chat_id'));
        $this->assertDatabaseHas('messages', [
            'profile_id' => $profile->id,
            'chat_id' => $response->json('data.chat_id'),
            'type' => 'question',
            'text' => 'Queue this message',
        ]);
        Event::assertDispatched(\App\Events\MessageStored::class);
    }

    public function test_store_returns_bad_gateway_when_chat_ai_generation_fails(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);
        $this->createActiveSubscriptionFor($user);

        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->withArgs(function (Profile $boundProfile, string $message) use ($profile): bool {
                return $boundProfile->is($profile) && $message === 'Pregunta que falla';
            })
            ->andReturn(new ChatAIAnswer(
                source: 'openai',
                answer: '',
                status: 'error',
                response: ['error' => 'Could not resolve host: api.openai.com'],
                requestUrl: 'https://api.openai.com/v1/chat/completions'
            ));

        $this->instance(ChatAIClient::class, $chatAiClient);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->postJson(self::ENDPOINT.'/'.$profile->id.'/messages', [
                'message' => 'Pregunta que falla',
            ]);

        $response->assertStatus(502);
        $response->assertJsonPath('message', 'Message answer generation failed.');
        $response->assertJsonPath('data.text', null);
        $response->assertJsonPath('data.audio_url', null);
        $response->assertJsonPath('data.source', 'openai');
        $response->assertJsonPath('data.status', 'error');
        $response->assertJsonPath('data.chat_ai.response.error', 'Could not resolve host: api.openai.com');

        $question = Message::where('profile_id', $profile->id)->where('type', 'question')->firstOrFail();

        $this->assertSame('Message answer generation failed.', $question->data['processing_error']);
        $this->assertSame('error', $question->data['chat_ai_error']['status']);
        $this->assertSame(0, Message::where('profile_id', $profile->id)->where('type', 'answer')->count());
    }

    public function test_store_audio_validates_audio_field(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($user);
        $token = $user->createToken('test-token', ['messages:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT.'/'.$profile->id.'/messages/audio', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['audio']);
    }

    public function test_user_without_messages_write_ability_can_not_send_audio_message(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($user);
        $token = $user->createToken('test-token', ['chat:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT.'/'.$profile->id.'/messages/audio', [
                'audio' => $this->validAudioUpload(),
            ]);

        $response->assertStatus(403);
    }

    public function test_user_can_send_audio_message_to_existing_chat(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($user);
        $this->createActiveSubscriptionFor($user);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $token = $user->createToken('test-token', ['messages:write'])->plainTextToken;

        $this->mockAudioChatClient(
            profile: $profile,
            transcribedText: 'Necesito ayuda con mi perfil',
            answerText: 'Claro, te ayudo con tu perfil.'
        );

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT.'/'.$profile->id.'/messages/audio', [
                'audio' => $this->validAudioUpload(),
                'chat_id' => $chat->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Message processed successfully.');
        $response->assertJsonPath('data.chat_id', $chat->id);
        $response->assertJsonPath('data.text', 'Claro, te ayudo con tu perfil.');
        $response->assertJsonPath('data.request_text', 'Necesito ayuda con mi perfil');

        $question = Message::where('chat_id', $chat->id)
            ->where('type', 'question')
            ->firstOrFail();

        $this->assertSame($question->id, $response->json('data.request_message_id'));
        $this->assertSame($question->audio, $response->json('data.request_audio_url'));
        $this->assertSame('Necesito ayuda con mi perfil', $question->text);
        $this->assertSame('api', $question->source);
        $this->assertNotNull($question->audio);
        $this->assertStringContainsString('/storage/messages/audio/'.$profile->id.'/', $question->audio);
        Storage::disk('public')->assertExists($this->storagePathFromUrl($question->audio));
        $this->assertSame('Necesito ayuda con mi perfil', $question->data['request']['message']);
        $this->assertSame($question->audio, $question->data['request']['audio_url']);
        $this->assertSame('success', $question->data['request']['transcription']['status']);
    }

    public function test_user_can_send_audio_message_and_new_chat_is_created_when_missing(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($user);
        $this->createActiveSubscriptionFor($user);
        $token = $user->createToken('test-token', ['messages:write'])->plainTextToken;

        $this->mockAudioChatClient(
            profile: $profile,
            transcribedText: 'Inicia una conversacion nueva',
            answerText: 'Conversacion iniciada.'
        );

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT.'/'.$profile->id.'/messages/audio', [
                'audio' => $this->validAudioUpload(),
            ]);

        $response->assertStatus(200);
        $chatId = $response->json('data.chat_id');

        $this->assertNotNull($chatId);
        $this->assertTrue(Chat::where('id', $chatId)->where('profile_id', $profile->id)->exists());

        $question = Message::where('chat_id', $chatId)
            ->where('type', 'question')
            ->firstOrFail();

        $this->assertSame('Inicia una conversacion nueva', $question->text);
        $this->assertNotNull($question->audio);
    }

    public function test_user_can_send_browser_webm_audio_message_detected_as_video_webm(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($user);
        $this->createActiveSubscriptionFor($user);
        $token = $user->createToken('test-token', ['messages:write'])->plainTextToken;

        $this->mockAudioChatClient(
            profile: $profile,
            transcribedText: 'Audio grabado desde el navegador',
            answerText: 'Respuesta para audio del navegador.'
        );

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT.'/'.$profile->id.'/messages/audio', [
                'audio' => $this->browserWebmAudioUpload(),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.text', 'Respuesta para audio del navegador.');
        $response->assertJsonPath('data.request_text', 'Audio grabado desde el navegador');

        $question = Message::where('profile_id', $profile->id)
            ->where('type', 'question')
            ->firstOrFail();

        $this->assertSame($question->id, $response->json('data.request_message_id'));
        $this->assertSame($question->audio, $response->json('data.request_audio_url'));
        $this->assertSame('Audio grabado desde el navegador', $question->text);
        $this->assertNotNull($question->audio);
        Storage::disk('public')->assertExists($this->storagePathFromUrl($question->audio));
    }

    public function test_store_audio_fails_when_chat_does_not_belong_to_profile(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($user);
        $otherProfile = $this->createProfileFor(User::factory()->create());
        $foreignChat = Chat::create(['profile_id' => $otherProfile->id]);
        $token = $user->createToken('test-token', ['messages:write'])->plainTextToken;

        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldNotReceive('getTextFromAudio');
        $chatAiClient->shouldNotReceive('getAnswer');
        $this->instance(ChatAIClient::class, $chatAiClient);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT.'/'.$profile->id.'/messages/audio', [
                'audio' => $this->validAudioUpload(),
                'chat_id' => $foreignChat->id,
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Chat not found.');
        $this->assertSame(0, Message::count());
    }

    public function test_user_can_not_send_audio_message_to_foreign_profile(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $owner = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($owner);
        $token = $user->createToken('test-token', ['messages:write'])->plainTextToken;

        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldNotReceive('getTextFromAudio');
        $chatAiClient->shouldNotReceive('getAnswer');
        $this->instance(ChatAIClient::class, $chatAiClient);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT.'/'.$profile->id.'/messages/audio', [
                'audio' => $this->validAudioUpload(),
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Profile not found.');
        $this->assertSame(0, Message::count());
    }

    public function test_admin_can_send_audio_message_to_any_profile(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($owner);
        $this->createActiveSubscriptionFor($owner);
        $token = $admin->createToken('test-token', ['messages:write'])->plainTextToken;

        $this->mockAudioChatClient(
            profile: $profile,
            transcribedText: 'Mensaje enviado por admin',
            answerText: 'Respuesta para admin.'
        );

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT.'/'.$profile->id.'/messages/audio', [
                'audio' => $this->validAudioUpload(),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.text', 'Respuesta para admin.');
        $this->assertDatabaseHas('messages', [
            'profile_id' => $profile->id,
            'type' => 'question',
            'text' => 'Mensaje enviado por admin',
            'source' => 'api',
        ]);
    }

    public function test_api_user_can_send_audio_message_to_any_profile(): void
    {
        Storage::fake('public');

        $apiUser = User::factory()->create(['role' => 'api']);
        $owner = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($owner);
        $this->createActiveSubscriptionFor($owner);
        $token = $apiUser->createToken('test-token', ['messages:write'])->plainTextToken;

        $this->mockAudioChatClient(
            profile: $profile,
            transcribedText: 'Audio enviado desde la web',
            answerText: 'Respuesta para audio web.'
        );

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT.'/'.$profile->id.'/messages/audio', [
                'audio' => $this->validAudioUpload(),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.text', 'Respuesta para audio web.');
        $this->assertDatabaseHas('messages', [
            'profile_id' => $profile->id,
            'type' => 'question',
            'text' => 'Audio enviado desde la web',
            'source' => 'api',
        ]);
    }

    public function test_store_audio_returns_error_when_transcription_fails(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($user);
        $this->createActiveSubscriptionFor($user);
        $token = $user->createToken('test-token', ['messages:write'])->plainTextToken;

        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getTextFromAudio')
            ->once()
            ->andReturn(new ChatAITextFromAudio(
                source: 'openai',
                audioPath: '/tmp/audio.webm',
                text: '',
                status: 'failed'
            ));
        $chatAiClient->shouldNotReceive('getAnswer');
        $this->instance(ChatAIClient::class, $chatAiClient);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT.'/'.$profile->id.'/messages/audio', [
                'audio' => $this->validAudioUpload(),
            ]);

        $response->assertStatus(502);
        $response->assertJsonPath('message', 'Audio transcription failed.');
        $response->assertJsonPath('data.status', 'failed');
        $this->assertSame(0, Message::count());
        Storage::disk('public')->assertMissing('messages/audio/'.$profile->id);
    }

    private function createProfileFor(User $user): Profile
    {
        return Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
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

    private function validAudioUpload(): UploadedFile
    {
        return UploadedFile::fake()->create('recording.webm', 128, 'audio/webm');
    }

    private function browserWebmAudioUpload(): UploadedFile
    {
        return UploadedFile::fake()->create('recording.webm', 128, 'video/webm');
    }

    private function mockAudioChatClient(Profile $profile, string $transcribedText, string $answerText): void
    {
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getTextFromAudio')
            ->once()
            ->withArgs(function (string $audioPath): bool {
                return file_exists($audioPath) && str_ends_with($audioPath, '.webm');
            })
            ->andReturn(new ChatAITextFromAudio(
                source: 'openai',
                audioPath: '/tmp/audio.webm',
                text: $transcribedText,
                status: 'success',
                confidence: 0.9,
                detectedLanguage: 'es',
                duration: 3.5
            ));

        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->withArgs(function (Profile $boundProfile, string $message) use ($profile, $transcribedText): bool {
                return $boundProfile->is($profile) && $message === $transcribedText;
            })
            ->andReturn(new ChatAIAnswer(
                source: 'openai',
                answer: $answerText,
                status: 'success'
            ));

        $this->instance(ChatAIClient::class, $chatAiClient);
    }

    private function storagePathFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;

        return ltrim(preg_replace('#^/storage/#', '', $path) ?? $path, '/');
    }
}
