<?php

namespace Tests\Unit\Classes\ChatAIService;

use App\Classes\ChatAIService\AnswerBuilder;
use App\Classes\ChatAIService\ChatAIAnswer;
use App\Classes\ChatAIService\ChatAIClient;
use App\Classes\VoiceService\VoiceClient;
use App\Classes\VoiceService\VoiceClientGeneratedAudio;
use App\Classes\VoiceService\VoiceManager;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionUsageType;
use App\Events\Subscriptions\SubscriptionUsageRequested;
use App\Exceptions\ChatAIService\ChatAIAnswerGenerationFailed;
use App\Models\Chat;
use App\Models\Message;
use App\Models\Profile;
use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\User;
use App\Models\Voice;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AnswerBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_get_answer_stores_audio_payload_when_generation_succeeds(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $this->createActiveSubscriptionFor($user);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'active' => true,
        ]);

        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'How are you?',
            'type' => 'question',
            'source' => 'api',
        ]);

        $voice = Voice::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'name' => 'Primary voice',
            'description' => 'desc',
            'source_voice_id' => 'voice_123',
            'source' => 'elevenlabs',
            'is_verified' => true,
            'active' => true,
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: 'Doing great!',
            status: 'success'
        );

        /** @var MockInterface&ChatAIClient $chatAiClient */
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'How are you?', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);

        /** @var MockInterface&VoiceClient $voiceClient */
        $voiceClient = Mockery::mock(VoiceClient::class);
        $voiceClient->shouldReceive('generateAudio')
            ->once()
            ->withArgs(function (Voice $providedVoice, string $text) use ($voice, $chatAiAnswer) {
                return $providedVoice->is($voice) && $text === $chatAiAnswer->answer;
            })
            ->andReturn(new VoiceClientGeneratedAudio(
                voice: $voice,
                text: $chatAiAnswer->answer,
                audioUrl: 'https://cdn.example.com/audio/answer.mp3',
                audioContent: null,
                audioFormat: 'mp3',
                duration: 2.5,
                status: 'success',
                metadata: ['length' => 2.5]
            ));

        /** @var MockInterface&VoiceManager $voiceManager */
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')
            ->once()
            ->with('elevenlabs')
            ->andReturn($voiceClient);

        $builder = new AnswerBuilder($chatAiClient, $voiceManager);

        $response = $builder->getAnswer($profile, $question)->toArray();
        $this->assertDatabaseHas('messages', [
            'chat_id' => $chat->id,
            'profile_id' => $profile->id,
            'type' => 'answer',
            'text' => 'Doing great!',
            'audio' => 'https://cdn.example.com/audio/answer.mp3',
        ]);

        $this->assertSame('https://cdn.example.com/audio/answer.mp3', $response['audio_url']);
        $this->assertSame('Doing great!', $response['text']);
        Event::assertDispatched(SubscriptionUsageRequested::class, function (SubscriptionUsageRequested $event) use ($profile, $question) {
            return $event->usageType === SubscriptionUsageType::ChatOpenAiCall
                && $event->userId === $profile->user_id
                && $event->profileId === $profile->id
                && $event->sourceId === (string) $question->id
                && $event->amounts === ['chat_messages' => 1];
        });
        Event::assertDispatched(SubscriptionUsageRequested::class, function (SubscriptionUsageRequested $event) use ($profile, $voice) {
            return $event->usageType === SubscriptionUsageType::VoiceTtsCharacters
                && $event->userId === $profile->user_id
                && $event->profileId === $profile->id
                && $event->sourceId === (string) $voice->id
                && $event->amounts === ['tts_characters' => strlen('Doing great!')];
        });
    }

    public function test_get_answer_throws_without_creating_answer_or_audio_when_chat_ai_fails(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'active' => true,
        ]);

        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'How are you?',
            'type' => 'question',
            'source' => 'api',
        ]);

        Voice::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'name' => 'Primary voice',
            'description' => 'desc',
            'source_voice_id' => 'voice_123',
            'source' => 'elevenlabs',
            'is_verified' => true,
            'active' => true,
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: '',
            status: 'error',
            response: ['error' => 'Could not resolve host: api.openai.com']
        );

        /** @var MockInterface&ChatAIClient $chatAiClient */
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'How are you?', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);

        /** @var MockInterface&VoiceManager $voiceManager */
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $builder = new AnswerBuilder($chatAiClient, $voiceManager);

        $this->expectException(ChatAIAnswerGenerationFailed::class);

        try {
            $builder->getAnswer($profile, $question);
        } finally {
            $this->assertSame(0, Message::where('chat_id', $chat->id)->where('type', 'answer')->count());
            Event::assertDispatched(SubscriptionUsageRequested::class, function (SubscriptionUsageRequested $event) use ($profile, $question) {
                return $event->usageType === SubscriptionUsageType::ChatOpenAiCall
                    && $event->userId === $profile->user_id
                    && $event->profileId === $profile->id
                    && $event->sourceId === (string) $question->id;
            });
        }
    }

    public function test_get_answer_without_active_voice_returns_null_audio(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
        ]);

        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Any updates?',
            'type' => 'question',
            'source' => 'api',
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: 'No updates yet.',
            status: 'success'
        );

        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'Any updates?', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);

        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $builder = new AnswerBuilder($chatAiClient, $voiceManager);

        $response = $builder->getAnswer($profile, $question)->toArray();

        $this->assertNull($response['audio_url']);
    }

    public function test_get_answer_attaches_first_selected_instagram_media_for_photo_request(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Muestrame una foto de Instagram',
            'type' => 'question',
            'source' => 'api',
        ]);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_user_id' => '17841400000000000',
            'username' => 'bigmelo',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_media_id' => 'older',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/older.jpg',
            'permalink' => 'https://www.instagram.com/p/older/',
            'caption' => 'Older',
            'observation' => 'Older note',
            'selected' => true,
            'taken_at' => now()->subDays(2),
        ]);
        ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_media_id' => 'newer',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/newer.jpg',
            'permalink' => 'https://www.instagram.com/p/newer/',
            'caption' => 'Newer',
            'observation' => 'Newer note',
            'selected' => true,
            'taken_at' => now()->subDay(),
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: '**Aqui** tienes una foto de Instagram. ![Newer](https://www.instagram.com/p/newer/) [Ver foto](https://www.instagram.com/p/newer/)',
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'Muestrame una foto de Instagram', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertStringNotContainsString('**', $response['text']);
        $this->assertStringNotContainsString('![', $response['text']);
        $this->assertStringNotContainsString('https://www.instagram.com/p/newer/', $response['text']);
        $this->assertStringContainsString('Puedes ver más fotos en Instagram.', $response['text']);
        $this->assertSame('https://example.com/newer.jpg', $response['media'][0]['image_url']);
        $this->assertSame('https://www.instagram.com/p/newer/', $response['media'][0]['permalink']);
        $this->assertSame('Newer note', $response['media'][0]['observation']);
        $this->assertSame('instagram', $response['media'][0]['provider_key']);
        $this->assertSame('Instagram', $response['media'][0]['provider_label']);
    }

    public function test_get_answer_uses_instagram_media_fallback_when_model_has_no_answer(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Muestrame una foto de Instagram',
            'type' => 'question',
            'source' => 'api',
        ]);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_user_id' => '17841400000000000',
            'username' => 'bigmelo',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_media_id' => 'media',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/media.jpg',
            'permalink' => 'https://www.instagram.com/p/media/',
            'caption' => 'Media',
            'observation' => 'Media note',
            'selected' => true,
            'taken_at' => now(),
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: '[[BIGMELO_NO_ANSWER]] No tengo esa información en este momento.',
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'Muestrame una foto de Instagram', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertStringNotContainsString('No tengo esa información', $response['text']);
        $this->assertStringContainsString('Te comparto esta foto: Media note.', $response['text']);
        $this->assertStringContainsString('Puedes ver más fotos en Instagram.', $response['text']);
        $this->assertStringNotContainsString('https://www.instagram.com/p/media/', $response['text']);
        $this->assertSame('https://example.com/media.jpg', $response['media'][0]['image_url']);
    }

    public function test_get_answer_attaches_model_selected_instagram_media_id(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Muestrame una foto',
            'type' => 'question',
            'source' => 'api',
        ]);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_user_id' => '17841400000000000',
            'username' => 'bigmelo',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $older = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_media_id' => 'older',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/older.jpg',
            'permalink' => 'https://www.instagram.com/p/older/',
            'caption' => 'Older',
            'observation' => 'Older note',
            'selected' => true,
            'taken_at' => now()->subDays(2),
        ]);
        $newer = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_media_id' => 'newer',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/newer.jpg',
            'permalink' => 'https://www.instagram.com/p/newer/',
            'caption' => 'Newer',
            'observation' => 'Newer note',
            'selected' => true,
            'taken_at' => now()->subDay(),
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'Te comparto esta foto en Roma.',
                'media_action' => 'show',
                'media_ids' => [$newer->id],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'Muestrame una foto', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame($newer->id, $response['media'][0]['id']);
        $this->assertNotSame($older->id, $response['media'][0]['id']);
        $this->assertSame('https://example.com/newer.jpg', $response['media'][0]['image_url']);
        $this->assertStringContainsString('Te comparto esta foto en Roma.', $response['text']);
        $this->assertStringContainsString('Puedes ver más fotos en Instagram.', $response['text']);
        $this->assertStringNotContainsString('https://www.instagram.com/p/newer/', $response['text']);
    }

    public function test_get_answer_keeps_previous_photo_context_without_attaching_new_media(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_user_id' => '17841400000000000',
            'username' => 'bigmelo',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $media = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_media_id' => 'rome',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/rome.jpg',
            'permalink' => 'https://www.instagram.com/p/rome/',
            'caption' => 'Rome',
            'observation' => 'Roma',
            'selected' => true,
            'taken_at' => now(),
        ]);
        Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Te comparto esta foto. Puedes ver más fotos en Instagram.',
            'type' => 'answer',
            'source' => 'openai',
            'data' => ['media' => [['id' => $media->id]]],
        ]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => '¿Dónde fue esa foto?',
            'type' => 'question',
            'source' => 'api',
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'Esa foto fue tomada en Roma.',
                'media_action' => 'none',
                'media_ids' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, '¿Dónde fue esa foto?', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame('Esa foto fue tomada en Roma.', $response['text']);
        $this->assertSame([], $response['media']);
    }

    public function test_get_answer_replaces_no_answer_text_when_media_is_attached(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Otra',
            'type' => 'question',
            'source' => 'api',
        ]);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_user_id' => '17841400000000000',
            'username' => 'bigmelo',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $media = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_media_id' => 'amsterdam',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/amsterdam.jpg',
            'permalink' => 'https://www.instagram.com/p/amsterdam/',
            'caption' => 'Amsterdam',
            'observation' => 'Amsterdam',
            'selected' => true,
            'taken_at' => now(),
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'No puedo responder eso. Pregúntame otra cosa.',
                'media_action' => 'show',
                'media_ids' => [$media->id],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'Otra', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertStringNotContainsString('No puedo responder eso', $response['text']);
        $this->assertStringContainsString('Esta foto fue tomada en Amsterdam.', $response['text']);
        $this->assertStringContainsString('Puedes ver más fotos en Instagram.', $response['text']);
        $this->assertSame($media->id, $response['media'][0]['id']);
    }

    public function test_get_answer_treats_any_choice_followup_as_photo_request_after_photo_context(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_user_id' => '17841400000000000',
            'username' => 'bigmelo',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $media = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_media_id' => 'rome',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/rome.jpg',
            'permalink' => 'https://www.instagram.com/p/rome/',
            'caption' => 'Roma',
            'observation' => 'Roma',
            'selected' => true,
            'taken_at' => now(),
        ]);
        Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'que foto tienes',
            'type' => 'question',
            'source' => 'api',
        ]);
        Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Tengo varias fotos en Instagram. ¿Te gustaría ver alguna en particular?',
            'type' => 'answer',
            'source' => 'openai',
        ]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'cualquiera',
            'type' => 'question',
            'source' => 'api',
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'No puedo responder eso. Pregúntame otra cosa.',
                'media_action' => 'none',
                'media_ids' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'cualquiera', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertStringNotContainsString('No puedo responder', $response['text']);
        $this->assertStringContainsString('Esta foto fue tomada en Roma.', $response['text']);
        $this->assertSame($media->id, $response['media'][0]['id']);
        $this->assertSame('https://example.com/rome.jpg', $response['media'][0]['image_url']);
    }

    public function test_get_answer_prefers_unseen_media_when_model_repeats_seen_media_for_any_choice_followup(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_user_id' => '17841400000000000',
            'username' => 'bigmelo',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $seen = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_media_id' => 'seen',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/seen.jpg',
            'permalink' => 'https://www.instagram.com/p/seen/',
            'caption' => 'Seen',
            'observation' => 'Seen note',
            'selected' => true,
            'taken_at' => now(),
        ]);
        $unseen = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_media_id' => 'unseen',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/unseen.jpg',
            'permalink' => 'https://www.instagram.com/p/unseen/',
            'caption' => 'Unseen',
            'observation' => 'Unseen note',
            'selected' => true,
            'taken_at' => now()->subDay(),
        ]);
        Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Te comparto esta foto. Puedes ver más fotos en Instagram.',
            'type' => 'answer',
            'source' => 'openai',
            'data' => ['media' => [['id' => $seen->id]]],
        ]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'cualquiera',
            'type' => 'question',
            'source' => 'api',
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'Te comparto esta foto.',
                'media_action' => 'show',
                'media_ids' => [$seen->id],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'cualquiera', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame($unseen->id, $response['media'][0]['id']);
        $this->assertSame('https://example.com/unseen.jpg', $response['media'][0]['image_url']);
        $this->assertStringContainsString('Te comparto esta foto: Unseen note.', $response['text']);
        $this->assertStringNotContainsString('Te comparto esta foto.', $response['text']);
    }

    public function test_get_answer_fallback_uses_unseen_media_for_another_photo_request(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_user_id' => '17841400000000000',
            'username' => 'bigmelo',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $older = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_media_id' => 'older',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/older.jpg',
            'permalink' => 'https://www.instagram.com/p/older/',
            'caption' => 'Older',
            'observation' => 'Older note',
            'selected' => true,
            'taken_at' => now()->subDays(2),
        ]);
        $newer = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_media_id' => 'newer',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/newer.jpg',
            'permalink' => 'https://www.instagram.com/p/newer/',
            'caption' => 'Newer',
            'observation' => 'Newer note',
            'selected' => true,
            'taken_at' => now()->subDay(),
        ]);
        Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Te comparto esta foto. Puedes ver más fotos en Instagram.',
            'type' => 'answer',
            'source' => 'openai',
            'data' => ['media' => [['id' => $newer->id]]],
        ]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Muéstrame otra',
            'type' => 'question',
            'source' => 'api',
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: 'Te muestro otra foto.',
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'Muéstrame otra', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame($older->id, $response['media'][0]['id']);
        $this->assertSame('https://example.com/older.jpg', $response['media'][0]['image_url']);
        $this->assertStringContainsString('Puedes ver más fotos en Instagram.', $response['text']);
    }

    public function test_get_answer_does_not_send_raw_media_url_to_audio_generation(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $this->createActiveSubscriptionFor($user);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'locale' => 'es',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Muestrame fotos',
            'type' => 'question',
            'source' => 'api',
        ]);
        $voice = Voice::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'name' => 'Primary voice',
            'description' => 'desc',
            'source_voice_id' => 'voice_123',
            'source' => 'elevenlabs',
            'is_verified' => true,
            'active' => true,
        ]);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_user_id' => '17841400000000000',
            'username' => 'bigmelo',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_media_id' => 'media',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/media.jpg',
            'permalink' => 'https://www.instagram.com/p/media/',
            'caption' => 'Foto en Mexico',
            'observation' => 'Mexico',
            'selected' => true,
            'taken_at' => now(),
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: 'Esta foto fue tomada en Mexico: https://www.instagram.com/p/media/',
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'Muestrame fotos', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);

        $spokenText = null;
        $voiceClient = Mockery::mock(VoiceClient::class);
        $voiceClient->shouldReceive('generateAudio')
            ->once()
            ->withArgs(function (Voice $providedVoice, string $text) use (&$spokenText, $voice) {
                $spokenText = $text;

                return $providedVoice->is($voice);
            })
            ->andReturn(new VoiceClientGeneratedAudio(
                voice: $voice,
                text: 'Esta foto fue tomada en Mexico. Puedes ver más fotos en Instagram.',
                audioUrl: 'https://cdn.example.com/audio/photo.mp3',
                audioContent: null,
                audioFormat: 'mp3',
                duration: 2.5,
                status: 'success',
                metadata: []
            ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')
            ->once()
            ->with('elevenlabs')
            ->andReturn($voiceClient);

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame('https://cdn.example.com/audio/photo.mp3', $response['audio_url']);
        $this->assertNotNull($spokenText);
        $this->assertStringNotContainsString('http', $spokenText);
        $this->assertStringContainsString('Instagram', $spokenText);
        $this->assertStringNotContainsString('https://www.instagram.com/p/media/', $response['text']);
    }

    public function test_get_answer_handles_voice_driver_failure(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
        ]);

        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Tell me a joke',
            'type' => 'question',
            'source' => 'api',
        ]);

        Voice::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'name' => 'Primary voice',
            'description' => 'desc',
            'source_voice_id' => 'voice_456',
            'source' => 'elevenlabs',
            'is_verified' => true,
            'active' => true,
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: 'Here is a joke.',
            status: 'success'
        );

        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'Tell me a joke', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);

        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')
            ->once()
            ->with('elevenlabs')
            ->andThrow(new \RuntimeException('Driver failure'));

        $builder = new AnswerBuilder($chatAiClient, $voiceManager);

        $response = $builder->getAnswer($profile, $question)->toArray();

        $this->assertNull($response['audio_url']);
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
