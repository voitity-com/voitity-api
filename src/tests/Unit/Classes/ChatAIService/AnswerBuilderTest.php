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
use App\Models\ProfileFeatureSetting;
use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\User;
use App\Models\Voice;
use App\Services\Features\FeatureService;
use App\Services\Products\ProfileProductService;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EnablesFeaturesForTestProfiles;
use Tests\TestCase;

class AnswerBuilderTest extends TestCase
{
    use EnablesFeaturesForTestProfiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableFeaturesForTestProfiles();
    }

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
        $this->assertDatabaseHas('subscription_uses', [
            'profile_id' => $profile->id,
            'source_id' => (string) $voice->id,
            'usage_type' => SubscriptionUsageType::VoiceTtsCharacters->value,
            'tts_characters_used' => strlen('Doing great!'),
            'status' => 'finalized',
        ]);
        $this->assertDatabaseMissing('subscription_uses', [
            'usage_type' => SubscriptionUsageType::ChatOpenAiCall->value,
        ]);
    }

    public function test_get_answer_attaches_only_requested_available_products(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($user)->create([
            'products_enabled' => true,
            'locale' => 'es',
        ]);
        $product = app(ProfileProductService::class)->create($profile, $user, [
            'name' => 'Proteína Whey',
            'description' => 'Proteína para complementar la recuperación deportiva.',
            'image_url' => 'https://images.example.com/protein.jpg',
            'destination_type' => 'whatsapp',
            'country_code' => '57',
            'phone_number' => '3001234567',
            'status' => 'published',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => '¿Qué podría complementar mi recuperación?',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'La Proteína Whey puede complementar tu recuperación junto con una alimentación adecuada.',
                'media_request' => false,
                'media_action' => 'none',
                'media_ids' => [],
                'product_request' => true,
                'product_action' => 'show',
                'product_ids' => [$product->id, 999999],
                'constraints' => [
                    'include_providers' => [],
                    'exclude_providers' => [],
                    'include_source_types' => [],
                    'exclude_source_types' => [],
                    'require_unseen' => false,
                ],
            ], JSON_THROW_ON_ERROR),
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertCount(1, $response['products']);
        $this->assertSame($product->id, $response['products'][0]['id']);
        $this->assertSame('Proteína Whey', $response['products'][0]['name']);
        $this->assertStringStartsWith('https://wa.me/573001234567?text=', $response['products'][0]['action_url']);
        $this->assertSame($response['products'], $response['data']['products']);
        $this->assertDatabaseHas('messages', [
            'chat_id' => $chat->id,
            'type' => 'answer',
            'text' => 'La Proteína Whey puede complementar tu recuperación junto con una alimentación adecuada.',
        ]);
    }

    public function test_get_answer_does_not_attach_products_when_profile_feature_is_disabled(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($user)->create([
            'products_enabled' => true,
            'locale' => 'es',
        ]);
        ProfileFeatureSetting::query()->updateOrCreate(
            [
                'profile_id' => $profile->id,
                'feature_key' => FeatureService::PRODUCTS,
            ],
            ['enabled' => false],
        );
        $product = app(ProfileProductService::class)->create($profile, $user, [
            'name' => 'Proteína Whey',
            'description' => 'Proteína para complementar la recuperación deportiva.',
            'image_url' => 'https://images.example.com/protein.jpg',
            'destination_type' => 'external_url',
            'destination_url' => 'https://shop.example.com/protein',
            'status' => 'published',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Muéstrame el producto recomendado.',
            'type' => 'question',
            'source' => 'api',
        ]);
        $answer = 'Puedo darte una recomendación general sin adjuntar productos.';

        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->andReturn(new ChatAIAnswer(
                source: 'openai',
                answer: json_encode([
                    'answer' => $answer,
                    'media_request' => false,
                    'media_action' => 'none',
                    'media_ids' => [],
                    'product_request' => true,
                    'product_action' => 'show',
                    'product_ids' => [$product->id],
                    'constraints' => [],
                ], JSON_THROW_ON_ERROR),
                status: 'success'
            ));

        $response = (new AnswerBuilder($chatAiClient, Mockery::mock(VoiceManager::class)))
            ->getAnswer($profile->fresh(), $question)
            ->toArray();

        $this->assertSame([], $response['products']);
        $this->assertSame([], $response['data']['products']);
        $this->assertSame($answer, $response['text']);
    }

    public function test_product_answer_can_use_up_to_four_hundred_characters(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($user)->create([
            'products_enabled' => true,
            'locale' => 'es',
        ]);
        $product = app(ProfileProductService::class)->create($profile, $user, [
            'name' => 'Cuaderno Universitario',
            'description' => 'Formato 21 x 29.7 cm, 100 hojas, precio $28.000.',
            'image_url' => 'https://images.example.com/notebook.jpg',
            'destination_type' => 'external_url',
            'destination_url' => 'https://shop.example.com/notebook',
            'status' => 'published',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Compara los cuadernos.',
            'type' => 'question',
            'source' => 'api',
        ]);
        $answer = str_repeat('Comparación completa de productos. ', 9);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->andReturn(new ChatAIAnswer(
                source: 'openai',
                answer: json_encode([
                    'answer' => $answer,
                    'media_request' => false,
                    'media_action' => 'none',
                    'media_ids' => [],
                    'product_request' => true,
                    'product_action' => 'show',
                    'product_ids' => [$product->id],
                    'constraints' => [],
                ], JSON_THROW_ON_ERROR),
                status: 'success'
            ));

        $response = (new AnswerBuilder($chatAiClient, Mockery::mock(VoiceManager::class)))
            ->getAnswer($profile, $question)
            ->toArray();

        $this->assertGreaterThan(200, mb_strlen($response['text']));
        $this->assertLessThanOrEqual(400, mb_strlen($response['text']));
        $this->assertSame(trim($answer), $response['text']);
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
            $this->assertDatabaseMissing('subscription_uses', [
                'profile_id' => $profile->id,
                'usage_type' => SubscriptionUsageType::ChatOpenAiCall->value,
            ]);
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
        $this->assertSame('IMAGE', $response['media'][0]['media_type']);
        $this->assertSame('https://example.com/newer.jpg', $response['media'][0]['image_url']);
        $this->assertSame('https://example.com/newer.jpg', $response['media'][0]['media_url']);
        $this->assertNull($response['media'][0]['thumbnail_url']);
        $this->assertSame('https://www.instagram.com/p/newer/', $response['media'][0]['permalink']);
        $this->assertSame('Newer note', $response['media'][0]['observation']);
        $this->assertSame('instagram', $response['media'][0]['provider_key']);
        $this->assertSame('Instagram', $response['media'][0]['provider_label']);
    }

    public function test_get_answer_does_not_attach_instagram_media_when_profile_feature_is_disabled(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'locale' => 'es',
        ]);
        ProfileFeatureSetting::query()->updateOrCreate(
            [
                'profile_id' => $profile->id,
                'feature_key' => FeatureService::INTEGRATIONS_INSTAGRAM,
            ],
            ['enabled' => false],
        );
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Muéstrame una foto de Instagram',
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
            'provider_media_id' => 'newer',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/newer.jpg',
            'permalink' => 'https://www.instagram.com/p/newer/',
            'caption' => 'Newer',
            'observation' => 'Newer note',
            'selected' => true,
            'taken_at' => now(),
        ]);

        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->andReturn(new ChatAIAnswer(
                source: 'openai',
                answer: json_encode([
                    'answer' => 'Te comparto esta foto.',
                    'media_request' => true,
                    'media_action' => 'show',
                    'media_ids' => [$media->id],
                    'constraints' => [
                        'include_providers' => [ProfileIntegration::PROVIDER_INSTAGRAM],
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                status: 'success'
            ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile->fresh(), $question)->toArray();

        $this->assertSame([], $response['media']);
        $this->assertSame([], $response['data']['media']);
        $this->assertSame('profile_media_rules', $response['source']);
        $this->assertStringContainsString('No tengo fotos disponibles en Instagram', $response['text']);
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

    public function test_rag_shows_instagram_media_when_spanish_request_uses_publicaciones(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
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
            'text' => 'Muéstrame tus publicaciones de Instagram',
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
            'provider_media_id' => 'publication',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/publication.jpg',
            'permalink' => 'https://www.instagram.com/p/publication/',
            'observation' => 'A July photo published by bigmelo.',
            'selected' => true,
            'taken_at' => now(),
        ]);

        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'Muéstrame tus publicaciones de Instagram', $question->chat_id, $question->id)
            ->andReturn(new ChatAIAnswer(
                source: 'openai',
                answer: json_encode([
                    'answer' => 'No hay publicaciones de Instagram disponibles en este momento.',
                    'media_request' => true,
                    'media_action' => 'none',
                    'media_ids' => [],
                    'product_request' => false,
                    'product_action' => 'none',
                    'product_ids' => [],
                    'references' => [],
                    'constraints' => [
                        'include_providers' => [],
                        'exclude_providers' => [],
                        'include_source_types' => [],
                        'exclude_source_types' => [],
                        'require_unseen' => false,
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                status: 'success',
                response: [
                    '_bigmelo' => [
                        'knowledge' => [
                            'mode' => 'rag',
                            'retrieved_sources' => [[
                                'source_type' => 'integration_media',
                                'source_id' => (string) $media->id,
                            ]],
                        ],
                    ],
                ],
            ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([$media->id], collect($response['media'])->pluck('id')->all());
        $this->assertSame('instagram', $response['media'][0]['provider_key']);
        $this->assertStringNotContainsString('No hay publicaciones', $response['text']);
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
                'media_request' => true,
                'media_action' => 'none',
                'media_ids' => [],
                'constraints' => [
                    'include_providers' => [],
                    'exclude_providers' => [],
                    'include_source_types' => [],
                    'exclude_source_types' => [],
                    'require_unseen' => false,
                ],
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
                'media_request' => true,
                'media_action' => 'show',
                'media_ids' => [$seen->id],
                'constraints' => [
                    'include_providers' => [],
                    'exclude_providers' => [],
                    'include_source_types' => [],
                    'exclude_source_types' => [],
                    'require_unseen' => false,
                ],
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

    public function test_get_answer_does_not_attach_excluded_provider_media_when_no_alternative_exists(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
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
            'text' => 'Muéstrame una foto que no tenga en instagram',
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
            'provider_media_id' => 'rome',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/rome.jpg',
            'permalink' => 'https://www.instagram.com/p/rome/',
            'caption' => 'Roma',
            'observation' => 'Roma',
            'selected' => true,
            'taken_at' => now(),
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'Esta foto fue tomada en Roma.',
                'media_request' => true,
                'media_action' => 'show',
                'media_ids' => [$media->id],
                'constraints' => [
                    'include_providers' => [],
                    'exclude_providers' => ['instagram'],
                    'include_source_types' => [],
                    'exclude_source_types' => [],
                    'require_unseen' => false,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'Muéstrame una foto que no tenga en instagram', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([], $response['media']);
        $this->assertSame('Por ahora solo tengo fotos de Instagram.', $response['text']);
        $this->assertLessThanOrEqual(200, mb_strlen($response['text']));
    }

    public function test_get_answer_uses_other_provider_when_requested_provider_is_excluded(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
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
            'text' => 'Muéstrame una foto que no sea de Instagram',
            'type' => 'question',
            'source' => 'api',
        ]);
        $instagram = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_user_id' => '17841400000000000',
            'username' => 'bigmelo',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $facebook = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => 'facebook',
            'provider_user_id' => 'fb_123',
            'username' => 'bigmelo.fb',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $instagramMedia = ProfileIntegrationMedia::create([
            'profile_integration_id' => $instagram->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_media_id' => 'ig-media',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/ig.jpg',
            'permalink' => 'https://www.instagram.com/p/ig/',
            'caption' => 'IG',
            'observation' => 'IG note',
            'selected' => true,
            'taken_at' => now(),
        ]);
        $facebookMedia = ProfileIntegrationMedia::create([
            'profile_integration_id' => $facebook->id,
            'profile_id' => $profile->id,
            'provider' => 'facebook',
            'provider_media_id' => 'fb-media',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/fb.jpg',
            'permalink' => 'https://www.facebook.com/photo/fb/',
            'caption' => 'FB',
            'observation' => 'Facebook note',
            'selected' => true,
            'taken_at' => now()->subDay(),
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'Te comparto esta foto.',
                'media_request' => true,
                'media_action' => 'show',
                'media_ids' => [$instagramMedia->id],
                'constraints' => [
                    'include_providers' => [],
                    'exclude_providers' => ['instagram'],
                    'include_source_types' => [],
                    'exclude_source_types' => [],
                    'require_unseen' => false,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'Muéstrame una foto que no sea de Instagram', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame($facebookMedia->id, $response['media'][0]['id']);
        $this->assertSame('facebook', $response['media'][0]['provider_key']);
        $this->assertSame('Facebook', $response['media'][0]['provider_label']);
        $this->assertStringContainsString('Facebook', $response['text']);
        $this->assertStringNotContainsString('Instagram', $response['text']);
        $this->assertLessThanOrEqual(200, mb_strlen($response['text']));
    }

    public function test_get_answer_uses_ai_media_constraints_for_contextual_provider_exclusion(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'locale' => 'es',
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
        ProfileIntegrationMedia::create([
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
            'text' => '¿Qué fotos tienes?',
            'type' => 'question',
            'source' => 'api',
        ]);
        Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Tengo algunas fotos en Instagram.',
            'type' => 'answer',
            'source' => 'openai',
        ]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Alguna que no sea de instagram',
            'type' => 'question',
            'source' => 'api',
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'No tengo fotos de otras fuentes por ahora.',
                'media_request' => true,
                'media_action' => 'none',
                'media_ids' => [],
                'constraints' => [
                    'include_providers' => [],
                    'exclude_providers' => ['instagram'],
                    'include_source_types' => [],
                    'exclude_source_types' => [],
                    'require_unseen' => false,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'Alguna que no sea de instagram', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([], $response['media']);
        $this->assertSame('Por ahora solo tengo fotos de Instagram.', $response['text']);
        $this->assertLessThanOrEqual(200, mb_strlen($response['text']));
    }

    public function test_get_answer_forces_available_media_when_ai_declines_clear_any_photo_request(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'locale' => 'es',
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
            'provider_media_id' => 'rome',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/rome.jpg',
            'permalink' => 'https://www.instagram.com/p/rome/',
            'caption' => 'Roma',
            'observation' => 'Roma',
            'selected' => true,
            'taken_at' => now(),
        ]);
        $unseen = ProfileIntegrationMedia::create([
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
            'taken_at' => now()->subDay(),
        ]);
        Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Aquí tienes una foto en Roma.',
            'type' => 'answer',
            'source' => 'openai',
            'data' => ['media' => [['id' => $seen->id]]],
        ]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Muestrame cualquier foto',
            'type' => 'question',
            'source' => 'api',
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'No puedo responder eso. Pregúntame otra cosa.',
                'media_request' => true,
                'media_action' => 'none',
                'media_ids' => [],
                'constraints' => [
                    'include_providers' => [],
                    'exclude_providers' => [],
                    'include_source_types' => [],
                    'exclude_source_types' => [],
                    'require_unseen' => false,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'Muestrame cualquier foto', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame($unseen->id, $response['media'][0]['id']);
        $this->assertStringNotContainsString('No tengo otras fotos', $response['text']);
        $this->assertStringContainsString('Amsterdam', $response['text']);
        $this->assertLessThanOrEqual(200, mb_strlen($response['text']));
    }

    public function test_get_answer_recovers_targeted_media_when_ai_mentions_matching_place_without_media_id(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'locale' => 'es',
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
        ProfileIntegrationMedia::create([
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
        $amsterdam = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_media_id' => 'amsterdam',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/amsterdam.jpg',
            'permalink' => 'https://www.instagram.com/p/amsterdam/',
            'caption' => null,
            'observation' => 'Amsterdam',
            'selected' => true,
            'taken_at' => now()->subDay(),
        ]);
        Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'He tomado fotos en Roma y Ámsterdam.',
            'type' => 'answer',
            'source' => 'openai',
        ]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Dejame ver la de Paises Bajos',
            'type' => 'question',
            'source' => 'api',
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'No tengo una foto específica de Ámsterdam disponible en este momento. Si deseas, puedo mostrarte otra foto que tomé en Roma.',
                'media_request' => true,
                'media_action' => 'none',
                'media_ids' => [],
                'constraints' => [
                    'include_providers' => [],
                    'exclude_providers' => [],
                    'include_source_types' => ['social_network'],
                    'exclude_source_types' => [],
                    'require_unseen' => false,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'Dejame ver la de Paises Bajos', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame($amsterdam->id, $response['media'][0]['id']);
        $this->assertStringNotContainsString('No tengo una foto', $response['text']);
        $this->assertStringContainsString('Amsterdam', $response['text']);
        $this->assertLessThanOrEqual(200, mb_strlen($response['text']));
    }

    public function test_get_answer_normalizes_declined_provider_followup_without_attaching_media_when_ai_misses_constraints(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'locale' => 'es',
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
        ProfileIntegrationMedia::create([
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
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Alguna no de instagram',
            'type' => 'question',
            'source' => 'api',
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'No tengo otras fotos para mostrarte en este momento. Si deseas ver más, puedes visitar mi Instagram.',
                'media_request' => true,
                'media_action' => 'none',
                'media_ids' => [],
                'constraints' => [
                    'include_providers' => [],
                    'exclude_providers' => [],
                    'include_source_types' => [],
                    'exclude_source_types' => [],
                    'require_unseen' => false,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'Alguna no de instagram', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([], $response['media']);
        $this->assertSame('Por ahora solo tengo fotos de Instagram.', $response['text']);
        $this->assertLessThanOrEqual(200, mb_strlen($response['text']));
    }

    public function test_get_answer_rejects_media_excluded_by_ai_source_type_constraints(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'locale' => 'es',
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
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => '¿Tienes fotos que no estén en redes sociales?',
            'type' => 'question',
            'source' => 'api',
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'Esta foto fue tomada en Roma.',
                'media_request' => true,
                'media_action' => 'show',
                'media_ids' => [$media->id],
                'constraints' => [
                    'include_providers' => [],
                    'exclude_providers' => [],
                    'include_source_types' => [],
                    'exclude_source_types' => ['social_network'],
                    'require_unseen' => false,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, '¿Tienes fotos que no estén en redes sociales?', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([], $response['media']);
        $this->assertSame('Por ahora solo tengo fotos de Instagram.', $response['text']);
        $this->assertLessThanOrEqual(200, mb_strlen($response['text']));
    }

    public function test_get_answer_prefers_explicit_tiktok_request_over_conflicting_ai_source_exclusion(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'locale' => 'es',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_TIKTOK,
            'provider_user_id' => 'tiktok-user',
            'username' => 'bigmelo',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $media = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_TIKTOK,
            'provider_media_id' => 'hulk-video',
            'media_type' => 'VIDEO',
            'media_url' => 'https://www.tiktok.com/@bigmelo/video/123',
            'thumbnail_url' => 'https://example.com/hulk.jpg',
            'permalink' => 'https://www.tiktok.com/@bigmelo/video/123',
            'observation' => 'Un video de Hulk',
            'selected' => true,
            'taken_at' => now(),
        ]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => '¿Qué tienes de TikTok?',
            'type' => 'question',
            'source' => 'api',
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'No tengo contenido disponible en TikTok.',
                'media_request' => true,
                'media_action' => 'none',
                'media_ids' => [],
                'constraints' => [
                    'include_providers' => [],
                    'exclude_providers' => [],
                    'include_source_types' => [],
                    'exclude_source_types' => ['social_network'],
                    'require_unseen' => false,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, '¿Qué tienes de TikTok?', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame($media->id, $response['media'][0]['id']);
        $this->assertSame('tiktok', $response['media'][0]['provider_key']);
        $this->assertStringNotContainsString('No tengo contenido', $response['text']);
        $this->assertStringContainsString('video', mb_strtolower($response['text']));
        $this->assertStringNotContainsString('foto', mb_strtolower($response['text']));
    }

    public function test_get_answer_returns_age_restricted_onlyfans_media_for_matching_topic(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'locale' => 'es',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_ONLYFANS,
            'provider_user_id' => 'creator',
            'username' => 'creator',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $media = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_ONLYFANS,
            'provider_media_id' => 'hulk-promo',
            'media_type' => 'IMAGE',
            'media_url' => 'https://cdn.example.com/onlyfans/hulk.jpg',
            'permalink' => 'https://onlyfans.com/creator',
            'observation' => 'Contenido promocional inspirado en Hulk con vestuario verde.',
            'age_restricted' => true,
            'selected' => true,
            'taken_at' => now(),
        ]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => '¿Qué tienes de Hulk en OnlyFans?',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'Tengo contenido promocional inspirado en Hulk. Espero que te guste.',
                'media_request' => true,
                'media_action' => 'show',
                'media_ids' => [$media->id],
                'constraints' => [
                    'include_providers' => ['onlyfans'],
                    'exclude_providers' => [],
                    'include_source_types' => [],
                    'exclude_source_types' => [],
                    'require_unseen' => false,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, '¿Qué tienes de Hulk en OnlyFans?', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame($media->id, $response['media'][0]['id']);
        $this->assertSame('onlyfans', $response['media'][0]['provider_key']);
        $this->assertSame('OnlyFans', $response['media'][0]['provider_label']);
        $this->assertTrue($response['media'][0]['age_restricted']);
        $this->assertStringContainsString('Hulk', $response['text']);
        $this->assertStringNotContainsString('Puedes ver', $response['text']);
        $this->assertStringNotContainsString('Espero que te guste', $response['text']);
    }

    public function test_get_answer_uses_media_specific_no_match_instead_of_generic_profile_fallback(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'locale' => 'es',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_ONLYFANS,
            'provider_user_id' => 'creator',
            'username' => 'creator',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_ONLYFANS,
            'provider_media_id' => 'unrelated-video',
            'media_type' => 'VIDEO',
            'media_url' => 'https://cdn.example.com/onlyfans/promo.mp4',
            'observation' => 'Video promocional hablando frente a cámara.',
            'age_restricted' => true,
            'selected' => true,
            'taken_at' => now(),
        ]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => '¿Qué tienes de Hulk en OnlyFans?',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => '[[BIGMELO_NO_ANSWER]] No tengo información sobre eso en este momento.',
                'media_request' => true,
                'media_action' => 'none',
                'media_ids' => [],
                'constraints' => [
                    'include_providers' => ['onlyfans'],
                    'exclude_providers' => [],
                    'include_source_types' => [],
                    'exclude_source_types' => [],
                    'require_unseen' => false,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, '¿Qué tienes de Hulk en OnlyFans?', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([], $response['media']);
        $this->assertSame('No tengo contenido coincidente disponible en OnlyFans por ahora.', $response['text']);
        $this->assertSame(
            'profile_media_rules',
            Message::query()->where('chat_id', $chat->id)->where('type', 'answer')->latest('id')->firstOrFail()->source
        );
    }

    public function test_get_answer_does_not_attach_unrelated_onlyfans_media_after_declining_requested_topic(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'locale' => 'es',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_ONLYFANS,
            'provider_user_id' => 'creator',
            'username' => 'creator',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_ONLYFANS,
            'provider_media_id' => 'unrelated-video',
            'media_type' => 'VIDEO',
            'media_url' => 'https://cdn.example.com/onlyfans/promo.mp4',
            'observation' => 'Video promocional hablando frente a cámara.',
            'age_restricted' => true,
            'selected' => true,
            'taken_at' => now(),
        ]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => '¿Qué tienes de Hulk en OnlyFans?',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'No tengo contenido específico de Hulk en OnlyFans. Pero puedes ver un video promocional en mi perfil.',
                'media_request' => true,
                'media_action' => 'none',
                'media_ids' => [],
                'constraints' => [
                    'include_providers' => [],
                    'exclude_providers' => [],
                    'include_source_types' => [],
                    'exclude_source_types' => [],
                    'require_unseen' => false,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, '¿Qué tienes de Hulk en OnlyFans?', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([], $response['media']);
        $this->assertSame('No tengo contenido coincidente disponible en OnlyFans por ahora.', $response['text']);
        $this->assertSame(
            'profile_media_rules',
            Message::query()->where('chat_id', $chat->id)->where('type', 'answer')->latest('id')->firstOrFail()->source
        );
    }

    public function test_get_answer_does_not_attach_onlyfans_video_when_user_requests_a_photo(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'locale' => 'es',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_ONLYFANS,
            'provider_user_id' => 'creator',
            'username' => 'creator',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $video = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_ONLYFANS,
            'provider_media_id' => 'lingerie-video',
            'media_type' => 'VIDEO',
            'media_url' => 'https://cdn.example.com/onlyfans/lingerie.mp4',
            'observation' => 'Video promocional en ropa interior.',
            'age_restricted' => true,
            'selected' => true,
            'taken_at' => now(),
        ]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Muéstrame una foto de OnlyFans en ropa interior.',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'Este video fue grabado en ropa interior.',
                'media_request' => true,
                'media_action' => 'show',
                'media_ids' => [$video->id],
                'constraints' => [
                    'include_providers' => ['onlyfans'],
                    'exclude_providers' => [],
                    'include_source_types' => [],
                    'exclude_source_types' => [],
                    'require_unseen' => false,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'Muéstrame una foto de OnlyFans en ropa interior.', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([], $response['media']);
        $this->assertSame('No tengo contenido coincidente disponible en OnlyFans por ahora.', $response['text']);
    }

    public function test_get_answer_limits_stored_and_returned_answer_to_400_characters(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
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
            'text' => 'Cuéntame sobre ti',
            'type' => 'question',
            'source' => 'api',
        ]);
        $longAnswer = str_repeat('Esta es una respuesta larga con detalles del perfil. ', 8);
        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => $longAnswer,
                'media_action' => 'none',
                'media_ids' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            status: 'success'
        );
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->with($profile, 'Cuéntame sobre ti', $question->chat_id, $question->id)
            ->andReturn($chatAiAnswer);
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();
        $storedAnswer = Message::query()
            ->where('chat_id', $chat->id)
            ->where('type', 'answer')
            ->latest('id')
            ->first();

        $this->assertLessThanOrEqual(400, mb_strlen($response['text']));
        $this->assertSame($response['text'], $storedAnswer?->text);
        $this->assertStringEndsWith('...', $response['text']);
    }

    public function test_get_answer_attaches_selected_youtube_video_for_provider_availability_question(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Abel Developer',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'locale' => 'es',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $media = $this->createSelectedYouTubeVideo($profile, $user);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Que videos de youtube tienes?',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->andReturn(new ChatAIAnswer(
                source: 'openai',
                answer: json_encode([
                    'answer' => 'No tengo videos de YouTube disponibles en este momento.',
                    'media_request' => true,
                    'media_action' => 'none',
                    'media_ids' => [],
                    'constraints' => [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                status: 'success'
            ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame($media->id, $response['media'][0]['id']);
        $this->assertSame('youtube', $response['media'][0]['provider_key']);
        $this->assertSame('https://www.youtube.com/@bigmeloai', $response['media'][0]['channel_url']);
        $this->assertStringContainsString('Te comparto este video', $response['text']);
        $this->assertStringContainsString('YouTube', $response['text']);
    }

    public function test_get_answer_recommends_youtube_video_when_question_matches_admin_description(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);
        config()->set('ai-knowledge.retrieval.proactive_media_enabled', true);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Abel Developer',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'locale' => 'es',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $media = $this->createSelectedYouTubeVideo($profile, $user);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Como creo un perfil?',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->andReturn(new ChatAIAnswer(
                source: 'openai',
                answer: json_encode([
                    'answer' => 'Puedes crear un perfil siguiendo las instrucciones de la plataforma.',
                    'media_request' => false,
                    'media_action' => 'none',
                    'media_ids' => [],
                    'constraints' => [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                status: 'success'
            ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame($media->id, $response['media'][0]['id']);
        $this->assertSame('youtube', $response['media'][0]['provider_key']);
        $this->assertStringContainsString('Puedes crear un perfil', $response['text']);
        $this->assertStringContainsString('Puedes verlo en YouTube', $response['text']);
    }

    public function test_get_answer_exposes_localized_other_integration_action_label(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Abel Developer',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'locale' => 'es',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_OTHER,
            'provider_user_id' => (string) $profile->id,
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $media = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_OTHER,
            'provider_media_id' => 'other-interview',
            'media_type' => 'IMAGE',
            'media_url' => 'https://cdn.example.com/interview.jpg',
            'permalink' => 'https://diario.example.com/interview',
            'caption' => 'Entrevista completa',
            'observation' => 'Entrevista sobre el lanzamiento del perfil.',
            'selected' => true,
            'taken_at' => now(),
            'metadata' => [
                'action_type' => 'read_on',
                'destination_type' => 'news_media',
                'source_type' => 'manual_upload',
            ],
        ]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Muéstrame la entrevista completa',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->andReturn(new ChatAIAnswer(
                source: 'openai',
                answer: json_encode([
                    'answer' => 'Aquí tienes la entrevista.',
                    'media_request' => true,
                    'media_action' => 'show',
                    'media_ids' => [$media->id],
                    'constraints' => [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                status: 'success',
            ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame($media->id, $response['media'][0]['id']);
        $this->assertSame('other', $response['media'][0]['provider_key']);
        $this->assertSame('Diario / Medio', $response['media'][0]['provider_label']);
        $this->assertSame('news_media', $response['media'][0]['destination_type']);
        $this->assertSame('read_on', $response['media'][0]['action_type']);
        $this->assertSame('Leer en el medio', $response['media'][0]['action_label']);
        $this->assertStringContainsString('Puedes leer en el medio', $response['text']);
    }

    #[DataProvider('implicitIntegrationMediaProvider')]
    public function test_get_answer_recommends_other_integration_media_when_question_matches_observation(
        string $provider,
        string $questionText,
        string $answerText,
        string $observation,
        string $mediaType,
        bool $ageRestricted
    ): void {
        Event::fake([SubscriptionUsageRequested::class]);
        config()->set('ai-knowledge.retrieval.proactive_media_enabled', true);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Abel Developer',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'locale' => 'es',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => "{$provider}-user",
            'username' => 'bigmelo',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $media = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => $provider,
            'provider_media_id' => "{$provider}-media",
            'media_type' => $mediaType,
            'media_url' => $mediaType === 'VIDEO'
                ? "https://example.com/{$provider}.mp4"
                : "https://example.com/{$provider}.jpg",
            'permalink' => "https://example.com/{$provider}/media",
            'caption' => ucfirst($provider).' media',
            'observation' => $observation,
            'age_restricted' => $ageRestricted,
            'selected' => true,
            'taken_at' => now(),
        ]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => $questionText,
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->andReturn(new ChatAIAnswer(
                source: 'openai',
                answer: json_encode([
                    'answer' => $answerText,
                    'media_request' => false,
                    'media_action' => 'none',
                    'media_ids' => [],
                    'constraints' => [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                status: 'success'
            ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame($media->id, $response['media'][0]['id']);
        $this->assertSame($provider, $response['media'][0]['provider_key']);
        $this->assertSame($ageRestricted, $response['media'][0]['age_restricted']);
    }

    #[DataProvider('socialLinkLocaleProvider')]
    public function test_get_answer_exposes_localized_social_link_cta(
        string $locale,
        string $questionText,
        string $answerText,
        string $expectedActionLabel
    ): void {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Abel Developer',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'locale' => $locale,
            'networks' => [
                'github' => 'https://github.com/aosmorac',
            ],
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => $questionText,
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->andReturn(new ChatAIAnswer(
                source: 'openai',
                answer: json_encode([
                    'answer' => $answerText,
                    'media_request' => false,
                    'media_action' => 'none',
                    'media_ids' => [],
                    'constraints' => [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                status: 'success'
            ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([
            [
                'provider_key' => 'github',
                'provider_label' => 'GitHub',
                'action_label' => $expectedActionLabel,
                'url' => 'https://github.com/aosmorac',
            ],
        ], $response['social_links']);
        $this->assertSame($response['social_links'], $response['data']['social_links']);
        $this->assertStringNotContainsString('https://', $response['text']);
    }

    public function test_get_answer_recovers_matching_other_media_when_model_marks_request_without_selection(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Abel Developer',
            'description' => 'Desc',
            'genre' => 'general',
            'personality' => 'friendly',
            'locale' => 'es',
            'networks' => [
                'github' => 'https://github.com/aosmorac',
            ],
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_OTHER,
            'provider_user_id' => (string) $profile->id,
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $media = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_OTHER,
            'provider_media_id' => 'other-github-code',
            'media_type' => 'IMAGE',
            'media_url' => 'https://cdn.example.com/github-code.jpg',
            'permalink' => 'https://github.com/tike-football/tike-api',
            'caption' => 'Código de perfiles',
            'observation' => 'Código del proyecto de perfiles construido en Laravel y React.',
            'selected' => true,
            'taken_at' => now(),
            'metadata' => [
                'action_type' => 'view_on',
                'destination_type' => 'github',
                'source_type' => 'manual_upload',
            ],
        ]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => '¿Algo de código que me muestres?',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')
            ->once()
            ->andReturn(new ChatAIAnswer(
                source: 'openai',
                answer: json_encode([
                    'answer' => 'Puedes ver mi código en GitHub: https://github.com/aosmorac.',
                    'media_request' => true,
                    'media_action' => 'none',
                    'media_ids' => [],
                    'constraints' => [],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                status: 'success'
            ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame($media->id, $response['media'][0]['id']);
        $this->assertSame('github', $response['media'][0]['destination_type']);
        $this->assertSame([], $response['social_links']);
    }

    public function test_rag_attaches_only_the_retrieved_media_for_an_explicit_request(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($user)->create(['locale' => 'es']);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_ONLYFANS,
            'provider_user_id' => 'creator',
            'username' => 'creator',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $retrieved = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_ONLYFANS,
            'provider_media_id' => 'paris-session',
            'media_type' => 'IMAGE',
            'media_url' => 'https://cdn.example.com/paris.jpg',
            'observation' => 'Sesión editorial del Proyecto Aurora en París.',
            'age_restricted' => true,
            'selected' => true,
        ]);
        $notRetrieved = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_ONLYFANS,
            'provider_media_id' => 'beach-session',
            'media_type' => 'IMAGE',
            'media_url' => 'https://cdn.example.com/beach.jpg',
            'observation' => 'Sesión de playa.',
            'age_restricted' => true,
            'selected' => true,
        ]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Muéstrame contenido de OnlyFans.',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')->once()->andReturn(new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'Aquí tienes contenido disponible.',
                'media_request' => true,
                'media_action' => 'none',
                'media_ids' => [],
                'constraints' => ['include_providers' => ['onlyfans']],
            ], JSON_THROW_ON_ERROR),
            status: 'success',
            response: [
                '_bigmelo' => [
                    'knowledge' => [
                        'mode' => 'rag',
                        'retrieved_sources' => [[
                            'source_type' => 'integration_media',
                            'source_id' => (string) $retrieved->id,
                        ]],
                    ],
                ],
            ],
        ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([$retrieved->id], collect($response['media'])->pluck('id')->all());
        $this->assertNotContains($notRetrieved->id, collect($response['media'])->pluck('id')->all());
    }

    public function test_rag_rejects_a_product_card_that_was_not_retrieved(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($user)->create(['products_enabled' => true]);
        $retrieved = app(ProfileProductService::class)->create($profile, $user, [
            'name' => 'Puma Orbita',
            'description' => 'Balón referencia 61385.',
            'image_url' => 'https://cdn.example.com/puma.jpg',
            'destination_type' => 'external_url',
            'destination_url' => 'https://shop.example.com/puma',
            'status' => 'published',
        ]);
        $notRetrieved = app(ProfileProductService::class)->create($profile, $user, [
            'name' => 'Adidas Tiro',
            'description' => 'Otro balón.',
            'image_url' => 'https://cdn.example.com/adidas.jpg',
            'destination_type' => 'external_url',
            'destination_url' => 'https://shop.example.com/adidas',
            'status' => 'published',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Muéstrame el balón 61385.',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')->once()->andReturn(new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'Este es el balón solicitado.',
                'media_action' => 'none',
                'media_ids' => [],
                'product_request' => true,
                'product_action' => 'show',
                'product_ids' => [$retrieved->id, $notRetrieved->id],
            ], JSON_THROW_ON_ERROR),
            status: 'success',
            response: [
                '_bigmelo' => [
                    'knowledge' => [
                        'mode' => 'rag',
                        'retrieved_sources' => [[
                            'source_type' => 'product',
                            'source_id' => (string) $retrieved->id,
                        ]],
                    ],
                ],
            ],
        ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([$retrieved->id], collect($response['products'])->pluck('id')->all());
    }

    public function test_default_rag_behavior_does_not_attach_media_without_a_media_request(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($user)->create(['locale' => 'es']);
        $media = $this->createSelectedYouTubeVideo($profile, $user);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => '¿Cómo creo un perfil?',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')->once()->andReturn(new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'Puedes crearlo desde el panel.',
                'media_request' => false,
                'media_action' => 'none',
                'media_ids' => [],
            ], JSON_THROW_ON_ERROR),
            status: 'success',
            response: [
                '_bigmelo' => [
                    'knowledge' => [
                        'mode' => 'rag',
                        'retrieved_sources' => [[
                            'source_type' => 'integration_media',
                            'source_id' => (string) $media->id,
                        ]],
                    ],
                ],
            ],
        ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([], $response['media']);
    }

    public function test_rag_does_not_attach_thematically_matching_media_when_proactive_media_is_disabled(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);
        config()->set('ai-knowledge.retrieval.proactive_media_enabled', false);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($user)->create(['locale' => 'es']);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_YOUTUBE,
            'provider_user_id' => 'UCfitness',
            'username' => '@fitness',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $media = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_YOUTUBE,
            'provider_media_id' => 'gym-session',
            'media_type' => 'VIDEO',
            'media_url' => 'https://www.youtube.com/watch?v=gym-session',
            'permalink' => 'https://www.youtube.com/watch?v=gym-session',
            'caption' => 'Entrenamiento en gimnasio',
            'observation' => 'Sesión para visitantes que entrenan en gimnasio.',
            'selected' => true,
            'taken_at' => now(),
        ]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Sí, voy al gimnasio.',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')->once()->andReturn(new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'Perfecto, en el gimnasio podemos organizar tu entrenamiento según tu experiencia.',
                'media_request' => false,
                'media_action' => 'none',
                'media_ids' => [],
                'product_request' => false,
                'product_action' => 'none',
                'product_ids' => [],
                'references' => [],
                'constraints' => [],
            ], JSON_THROW_ON_ERROR),
            status: 'success',
            response: [
                '_bigmelo' => [
                    'knowledge' => [
                        'mode' => 'rag',
                        'retrieved_sources' => [[
                            'source_type' => 'integration_media',
                            'source_id' => (string) $media->id,
                        ]],
                    ],
                ],
            ],
        ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([], $response['media']);
        $this->assertStringNotContainsString('YouTube', $response['text']);
    }

    public function test_product_request_takes_priority_over_unrequested_media_and_recovers_the_retrieved_product(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);
        config()->set('ai-knowledge.retrieval.proactive_media_enabled', true);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($user)->create([
            'locale' => 'es',
            'products_enabled' => true,
        ]);
        $product = app(ProfileProductService::class)->create($profile, $user, [
            'name' => 'Entrenamiento personalizado',
            'description' => 'Plan personalizado con seguimiento y una primera clase gratis.',
            'image_url' => 'https://cdn.example.com/training.jpg',
            'destination_type' => 'external_url',
            'destination_url' => 'https://example.com/training',
            'status' => 'published',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $media = $this->createSelectedYouTubeVideo($profile, $user);
        $media->forceFill([
            'caption' => 'Sofía puede entrenar a sus clientes',
            'observation' => 'Video sobre cómo Sofía puede entrenar de forma personalizada.',
        ])->save();
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => '¿Me puedes entrenar?',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')->once()->andReturn(new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'Sí, puedo entrenarte con un plan personalizado y seguimiento.',
                'media_request' => true,
                'media_action' => 'show',
                'media_ids' => [$media->id],
                'product_request' => true,
                'product_action' => 'none',
                'product_ids' => [],
                'references' => [],
                'constraints' => [],
            ], JSON_THROW_ON_ERROR),
            status: 'success',
            response: [
                '_bigmelo' => [
                    'knowledge' => [
                        'mode' => 'rag',
                        'retrieved_sources' => [
                            ['source_type' => 'product', 'source_id' => (string) $product->id],
                            ['source_type' => 'integration_media', 'source_id' => (string) $media->id],
                        ],
                    ],
                ],
            ],
        ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([$product->id], collect($response['products'])->pluck('id')->all());
        $this->assertSame([], $response['media']);
        $this->assertStringNotContainsString('YouTube', $response['text']);
    }

    public function test_explicit_media_request_can_show_media_together_with_a_relevant_product(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($user)->create([
            'locale' => 'es',
            'products_enabled' => true,
        ]);
        $product = app(ProfileProductService::class)->create($profile, $user, [
            'name' => 'Entrenamiento personalizado',
            'description' => 'Plan personalizado con seguimiento.',
            'image_url' => 'https://cdn.example.com/training.jpg',
            'destination_type' => 'external_url',
            'destination_url' => 'https://example.com/training',
            'status' => 'published',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $media = $this->createSelectedYouTubeVideo($profile, $user);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Muéstrame un video y el entrenamiento personalizado.',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')->once()->andReturn(new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'Te muestro el video y la opción de entrenamiento personalizado.',
                'media_request' => true,
                'media_action' => 'show',
                'media_ids' => [$media->id],
                'product_request' => true,
                'product_action' => 'show',
                'product_ids' => [$product->id],
                'references' => [],
                'constraints' => [],
            ], JSON_THROW_ON_ERROR),
            status: 'success',
            response: [
                '_bigmelo' => [
                    'knowledge' => [
                        'mode' => 'rag',
                        'retrieved_sources' => [
                            ['source_type' => 'product', 'source_id' => (string) $product->id],
                            ['source_type' => 'integration_media', 'source_id' => (string) $media->id],
                        ],
                    ],
                ],
            ],
        ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([$product->id], collect($response['products'])->pluck('id')->all());
        $this->assertSame([$media->id], collect($response['media'])->pluck('id')->all());
    }

    public function test_rag_recognizes_an_infographic_as_an_explicit_other_media_request(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($user)->create(['locale' => 'es']);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_OTHER,
            'provider_user_id' => (string) $profile->id,
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $media = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_OTHER,
            'provider_media_id' => 'prisma-infographic',
            'media_type' => 'IMAGE',
            'media_url' => 'https://cdn.example.com/prisma.jpg',
            'caption' => 'Infografía del Proyecto Prisma sobre pgvector.',
            'observation' => 'Arquitectura con Laravel, React, PostgreSQL, pgvector y AWS.',
            'selected' => true,
            'metadata' => ['destination_type' => 'blog'],
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Muéstrame la infografía del Proyecto Prisma sobre pgvector.',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')->once()->andReturn(new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'Tengo una infografía relacionada.',
                'media_request' => false,
                'media_action' => 'none',
                'media_ids' => [],
                'constraints' => ['include_source_types' => ['other']],
            ], JSON_THROW_ON_ERROR),
            status: 'success',
            response: [
                '_bigmelo' => [
                    'knowledge' => [
                        'mode' => 'rag',
                        'retrieved_sources' => [[
                            'source_type' => 'integration_media',
                            'source_id' => (string) $media->id,
                        ]],
                    ],
                ],
            ],
        ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([$media->id], collect($response['media'])->pluck('id')->all());
        $this->assertSame('other', $response['media'][0]['provider_key']);
    }

    public function test_rag_recovers_an_indirect_social_link_and_replaces_a_contradictory_answer(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($user)->create([
            'locale' => 'es',
            'networks' => [
                'linkedin' => 'https://www.linkedin.com/in/qa-big-melo',
                'tiktok' => 'https://www.tiktok.com/@qa_big_melo',
            ],
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => '¿Dónde encuentro tu red profesional oficial?',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')->once()->andReturn(new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => '[[BIGMELO_NO_ANSWER]] No tengo esa información.',
                'media_request' => false,
                'media_action' => 'none',
                'media_ids' => [],
                'product_request' => false,
                'product_action' => 'none',
                'product_ids' => [],
                'references' => [],
                'constraints' => [],
            ], JSON_THROW_ON_ERROR),
            status: 'success',
            response: [
                '_bigmelo' => [
                    'knowledge' => [
                        'mode' => 'rag',
                        'retrieved_sources' => [[
                            'source_type' => 'social_link',
                            'source_id' => 'linkedin',
                        ]],
                    ],
                ],
            ],
        ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame(['linkedin'], collect($response['social_links'])->pluck('provider_key')->all());
        $this->assertSame('Ir a LinkedIn', $response['social_links'][0]['action_label']);
        $this->assertStringContainsString('LinkedIn', $response['text']);
        $this->assertStringNotContainsString('No tengo', $response['text']);
    }

    public function test_rag_accepts_a_validated_structured_social_reference(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($user)->create([
            'locale' => 'es',
            'networks' => [
                'github' => 'https://github.com/qa-big-melo',
                'linkedin' => 'https://www.linkedin.com/in/qa-big-melo',
            ],
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => '¿Dónde compartes tus proyectos?',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')->once()->andReturn(new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'Comparto mis proyectos en el perfil oficial de GitHub.',
                'media_request' => false,
                'media_action' => 'none',
                'media_ids' => [],
                'product_request' => false,
                'product_action' => 'none',
                'product_ids' => [],
                'references' => [
                    ['type' => 'social_link', 'id' => 'github'],
                    ['type' => 'social_link', 'id' => 'linkedin'],
                ],
                'constraints' => [],
            ], JSON_THROW_ON_ERROR),
            status: 'success',
            response: [
                '_bigmelo' => [
                    'knowledge' => [
                        'mode' => 'rag',
                        'retrieved_sources' => [[
                            'source_type' => 'social_link',
                            'source_id' => 'github',
                        ]],
                    ],
                ],
            ],
        ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame(['github'], collect($response['social_links'])->pluck('provider_key')->all());
    }

    public function test_rag_recovers_product_cards_from_exact_names_in_the_answer(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($user)->create(['products_enabled' => true]);
        $guide = app(ProfileProductService::class)->create($profile, $user, [
            'name' => 'Guía Brújula 13',
            'description' => 'Precio de referencia: USD 15.',
            'image_url' => 'https://cdn.example.com/brujula.png',
            'destination_type' => 'external_url',
            'destination_url' => 'https://example.com/brujula',
            'status' => 'published',
        ]);
        $session = app(ProfileProductService::class)->create($profile, $user, [
            'name' => 'Sesión Prisma 24',
            'description' => 'Precio de referencia: USD 60.',
            'image_url' => 'https://cdn.example.com/prisma.png',
            'destination_type' => 'external_url',
            'destination_url' => 'https://example.com/prisma',
            'status' => 'published',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => '¿Cuál es el producto más barato y cuál el más costoso?',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')->once()->andReturn(new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'La Guía Brújula 13 es la más barata y la Sesión Prisma 24 es la más costosa.',
                'media_action' => 'none',
                'media_ids' => [],
                'product_request' => false,
                'product_action' => 'none',
                'product_ids' => [],
                'references' => [],
            ], JSON_THROW_ON_ERROR),
            status: 'success',
            response: [
                '_bigmelo' => [
                    'knowledge' => [
                        'mode' => 'rag',
                        'retrieved_sources' => [
                            ['source_type' => 'product', 'source_id' => (string) $guide->id],
                            ['source_type' => 'product', 'source_id' => (string) $session->id],
                        ],
                    ],
                ],
            ],
        ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertEqualsCanonicalizing(
            [$guide->id, $session->id],
            collect($response['products'])->pluck('id')->all()
        );
    }

    public function test_rag_deterministically_attaches_a_retrieved_product_for_a_concrete_need(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($user)->create([
            'locale' => 'es',
            'products_enabled' => true,
        ]);
        $product = app(ProfileProductService::class)->create($profile, $user, [
            'name' => 'Bigmelo',
            'description' => 'Crea una presencia digital interactiva con inteligencia artificial.',
            'image_url' => 'https://cdn.example.com/bigmelo.png',
            'destination_type' => 'external_url',
            'destination_url' => 'https://bigmelo.com/#plans',
            'status' => 'published',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Yo quiero construir mi perfil con inteligencia artificial',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')->once()->andReturn(new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'Puedo ayudarte a construir una presencia digital interactiva.',
                'media_request' => false,
                'media_action' => 'none',
                'media_ids' => [],
                'product_request' => false,
                'product_action' => 'none',
                'product_ids' => [],
                'references' => [],
                'constraints' => [],
            ], JSON_THROW_ON_ERROR),
            status: 'success',
            response: [
                '_bigmelo' => [
                    'knowledge' => [
                        'mode' => 'rag',
                        'retrieved_sources' => [[
                            'source_type' => 'product',
                            'source_id' => (string) $product->id,
                        ]],
                    ],
                ],
            ],
        ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([$product->id], collect($response['products'])->pluck('id')->all());
        $this->assertSame('Puedo ayudarte a construir una presencia digital interactiva.', $response['text']);
    }

    public function test_rag_ignores_media_types_misplaced_in_source_type_constraints(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($user)->create(['locale' => 'es']);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_ONLYFANS,
            'provider_user_id' => 'qa',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $media = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_ONLYFANS,
            'provider_media_id' => 'ambar-58',
            'media_type' => 'IMAGE',
            'media_url' => 'https://cdn.example.com/ambar.png',
            'observation' => 'ONLYFANS-AMBAR-58 es una lámina privada sobre composición visual.',
            'selected' => true,
            'age_restricted' => true,
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => '¿Tienes una imagen exclusiva de diseño visual?',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')->once()->andReturn(new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'Tengo una lámina privada sobre composición visual.',
                'media_request' => true,
                'media_action' => 'show',
                'media_ids' => [$media->id],
                'references' => [['type' => 'integration_media', 'id' => (string) $media->id]],
                'constraints' => [
                    'include_providers' => ['onlyfans'],
                    'include_source_types' => ['IMAGE'],
                ],
            ], JSON_THROW_ON_ERROR),
            status: 'success',
            response: [
                '_bigmelo' => [
                    'knowledge' => [
                        'mode' => 'rag',
                        'retrieved_sources' => [[
                            'source_type' => 'integration_media',
                            'source_id' => (string) $media->id,
                        ]],
                    ],
                ],
            ],
        ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([$media->id], collect($response['media'])->pluck('id')->all());
    }

    public function test_rag_infers_an_omitted_media_reference_from_a_short_uppercase_identifier(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($user)->create(['locale' => 'es']);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_OTHER,
            'provider_user_id' => (string) $profile->id,
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $media = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_OTHER,
            'provider_media_id' => 'rio-70',
            'media_type' => 'IMAGE',
            'media_url' => 'https://cdn.example.com/rio.png',
            'permalink' => 'https://example.com/blog/rio-70',
            'observation' => 'OTRO-RIO-70 anuncia la edición número doce del boletín Río.',
            'selected' => true,
            'metadata' => ['destination_type' => 'blog'],
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Muéstrame el anuncio RIO del blog.',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')->once()->andReturn(new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'OTRO-RIO-70 anuncia la edición número doce del boletín Río.',
                'media_request' => false,
                'media_action' => 'none',
                'media_ids' => [],
                'references' => [],
                'constraints' => [],
            ], JSON_THROW_ON_ERROR),
            status: 'success',
            response: [
                '_bigmelo' => [
                    'knowledge' => [
                        'mode' => 'rag',
                        'retrieved_sources' => [[
                            'source_type' => 'integration_media',
                            'source_id' => (string) $media->id,
                        ]],
                    ],
                ],
            ],
        ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([$media->id], collect($response['media'])->pluck('id')->all());
    }

    public function test_rag_keeps_a_valid_media_id_when_subject_wording_is_only_semantically_related(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($user)->create(['locale' => 'es']);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_TIKTOK,
            'provider_user_id' => 'qa',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $media = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_TIKTOK,
            'provider_media_id' => 'luna-25',
            'media_type' => 'VIDEO',
            'media_url' => 'https://www.tiktok.com/@qa/video/luna-25',
            'permalink' => 'https://www.tiktok.com/@qa/video/luna-25',
            'observation' => 'TIKTOK-LUNA-25 muestra una rutina de planificación de siete minutos.',
            'selected' => true,
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Muéstrame tu contenido corto para planificar rápido.',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')->once()->andReturn(new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => 'Aquí tienes una rutina rápida de planificación.',
                'media_request' => true,
                'media_action' => 'show',
                'media_ids' => [$media->id],
                'references' => [['type' => 'social_link', 'id' => 'tiktok']],
                'constraints' => [
                    'include_providers' => ['tiktok'],
                    'include_source_types' => ['social_network'],
                ],
            ], JSON_THROW_ON_ERROR),
            status: 'success',
            response: [
                '_bigmelo' => [
                    'knowledge' => [
                        'mode' => 'rag',
                        'retrieved_sources' => [[
                            'source_type' => 'integration_media',
                            'source_id' => (string) $media->id,
                        ]],
                    ],
                ],
            ],
        ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertSame([$media->id], collect($response['media'])->pluck('id')->all());
    }

    public function test_rag_answers_a_strongly_matching_media_fact_when_the_model_returns_no_answer(): void
    {
        Event::fake([SubscriptionUsageRequested::class]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($user)->create(['locale' => 'es']);
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_TIKTOK,
            'provider_user_id' => 'qa',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $media = ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_TIKTOK,
            'provider_media_id' => 'luna-25',
            'media_type' => 'VIDEO',
            'media_url' => 'https://www.tiktok.com/@qa/video/luna-25',
            'observation' => 'TIKTOK-LUNA-25 muestra una rutina de planificación de siete minutos.',
            'selected' => true,
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => '¿Cuántos minutos dura la rutina LUNA?',
            'type' => 'question',
            'source' => 'api',
        ]);
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getAnswer')->once()->andReturn(new ChatAIAnswer(
            source: 'openai',
            answer: json_encode([
                'answer' => '[[BIGMELO_NO_ANSWER]]',
                'media_request' => false,
                'media_action' => 'none',
                'media_ids' => [],
                'references' => [],
                'constraints' => [],
            ], JSON_THROW_ON_ERROR),
            status: 'success',
            response: [
                '_bigmelo' => [
                    'knowledge' => [
                        'mode' => 'rag',
                        'retrieved_sources' => [[
                            'source_type' => 'integration_media',
                            'source_id' => (string) $media->id,
                        ]],
                    ],
                ],
            ],
        ));
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();

        $response = (new AnswerBuilder($chatAiClient, $voiceManager))->getAnswer($profile, $question)->toArray();

        $this->assertStringContainsString('siete minutos', $response['text']);
        $this->assertSame([], $response['media']);
        $this->assertSame('profile_media_fact_rules', $response['source']);
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
            'tts_characters_remaining' => 20000,
            'chat_messages_remaining' => 1000,
            'credits_remaining' => 1000,
        ]);

        return $subscription;
    }

    private function createSelectedYouTubeVideo(Profile $profile, User $user): ProfileIntegrationMedia
    {
        $integration = ProfileIntegration::create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_YOUTUBE,
            'provider_user_id' => 'UCbigmelo',
            'username' => '@bigmeloai',
            'status' => ProfileIntegration::STATUS_CONNECTED,
            'metadata' => [
                'channel_url' => 'https://www.youtube.com/@bigmeloai',
            ],
        ]);

        return ProfileIntegrationMedia::create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_YOUTUBE,
            'provider_media_id' => '4gJl-UWeIvU',
            'media_type' => 'VIDEO',
            'media_url' => 'https://www.youtube.com/watch?v=4gJl-UWeIvU',
            'permalink' => 'https://www.youtube.com/watch?v=4gJl-UWeIvU',
            'thumbnail_url' => 'https://i.ytimg.com/vi/4gJl-UWeIvU/maxresdefault.jpg',
            'caption' => 'bigmelo Crea tu perfil inteligente',
            'observation' => 'Cómo crear un perfil en bigmelo',
            'selected' => true,
            'taken_at' => now(),
        ]);
    }

    /**
     * @return array<string, array{string, string, string, string, string, bool}>
     */
    public static function implicitIntegrationMediaProvider(): array
    {
        return [
            'instagram' => [
                ProfileIntegration::PROVIDER_INSTAGRAM,
                '¿Qué sabes del evento en Medellín?',
                'No tengo información sobre el evento en Medellín.',
                'Una foto del evento tecnológico en Medellín.',
                'IMAGE',
                false,
            ],
            'tiktok' => [
                ProfileIntegration::PROVIDER_TIKTOK,
                '¿Qué tienes de Hulk?',
                'No tengo información sobre Hulk.',
                'Un video de Hulk, un muñequito para los lápices.',
                'VIDEO',
                false,
            ],
            'onlyfans' => [
                ProfileIntegration::PROVIDER_ONLYFANS,
                '¿Qué tienes de tanga roja?',
                'No tengo información sobre la tanga roja.',
                'Video promocional en ropa interior con tanga roja.',
                'VIDEO',
                true,
            ],
        ];
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function socialLinkLocaleProvider(): array
    {
        return [
            'spanish' => [
                'es',
                '¿Qué tienes en GitHub?',
                'Puedes ver mis proyectos en GitHub: https://github.com/aosmorac.',
                'Ir a GitHub',
            ],
            'english' => [
                'en',
                'What do you have on GitHub?',
                'You can see my projects on GitHub: https://github.com/aosmorac.',
                'Go to GitHub',
            ],
        ];
    }
}
