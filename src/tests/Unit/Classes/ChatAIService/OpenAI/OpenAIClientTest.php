<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\ChatAIService\OpenAI;

use App\Classes\ChatAIService\ChatAIAnswer;
use App\Classes\ChatAIService\ChatAITextFromAudio;
use App\Classes\ChatAIService\OpenAI\OpenAIClient;
use App\Models\Chat;
use App\Models\Message;
use App\Models\Profile;
use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use App\Models\User;
use App\Services\Products\ProfileProductService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenAIClientTest extends TestCase
{
    private function makeClient(int $retryAttempts = 1, int $retryDelayMs = 0): OpenAIClient
    {
        return new OpenAIClient(
            apiKey: 'test-api-key',
            baseUrl: 'https://fake-openai.test/v1',
            defaultModel: 'gpt-4o-mini',
            whisperModel: 'whisper-1',
            retryAttempts: $retryAttempts,
            retryDelayMs: $retryDelayMs,
        );
    }

    private function makeProfile(): Profile
    {
        $profile = new Profile;
        $profile->name = 'Lex';
        $profile->description = 'Lawyer assistant';
        $profile->genre = 'legal';
        $profile->personality = 'friendly';
        $profile->data = [
            'me' => [
                'bio' => 'Legal assistant profile for tests.',
            ],
            'work' => [
                'company' => 'Voitity',
            ],
        ];

        return $profile;
    }

    #[Test]
    public function it_returns_successful_chat_answer(): void
    {
        Log::spy();

        Http::fake([
            'https://fake-openai.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-123',
                'choices' => [
                    [
                        'message' => ['content' => 'Sure, I can help!'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 120,
                    'completion_tokens' => 60,
                    'total_tokens' => 180,
                ],
                'model' => 'gpt-4o-mini',
            ], 200),
        ]);

        $client = $this->makeClient();
        $profile = $this->makeProfile();

        $answer = $client->getAnswer($profile, 'How do I file an appeal?');

        $this->assertInstanceOf(ChatAIAnswer::class, $answer);
        $this->assertTrue($answer->isSuccessful());
        $this->assertSame('Sure, I can help!', $answer->answer);
        $this->assertSame('success', $answer->status);
        $this->assertSame('https://fake-openai.test/v1/chat/completions', $answer->requestUrl);
        $this->assertSame('openai', $answer->source);
        $this->assertSame(1.0, $answer->confidence);
        $this->assertEquals('chatcmpl-123', $answer->response['id']);

        Http::assertSent(function ($request) use ($profile) {
            $payload = $request->data();
            $systemPrompt = $payload['messages'][0]['content'];

            return $request->url() === 'https://fake-openai.test/v1/chat/completions'
                && $payload['model'] === 'gpt-4o-mini'
                && $payload['response_format'] === ['type' => 'json_object']
                && $payload['messages'][0]['role'] === 'system'
                && str_starts_with($systemPrompt, 'Your name is: '.$profile->name)
                && str_contains($systemPrompt, '. '.$profile->description)
                && str_contains($systemPrompt, 'Your personality is '.$profile->personality)
                && str_contains($systemPrompt, 'Profile data:')
                && str_contains($systemPrompt, '"company":"Voitity"')
                && str_contains($systemPrompt, 'Only answer using the information in this prompt')
                && str_contains($systemPrompt, 'you do not have that information at this moment')
                && str_contains($systemPrompt, 'Make the conversation feel natural and progressive')
                && str_contains($systemPrompt, 'decide whether a short or detailed answer is appropriate')
                && str_contains($systemPrompt, 'Do not reveal all profile information at once')
                && ! str_contains($systemPrompt, 'Provide legal advice')
                && ! str_contains($systemPrompt, 'Always maintain a warm, approachable tone');
        });
    }

    #[Test]
    public function it_includes_recent_chat_messages_in_the_system_prompt(): void
    {
        Log::spy();

        Http::fake([
            'https://fake-openai.test/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => ['content' => 'Context-aware answer.'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 120,
                    'completion_tokens' => 60,
                    'total_tokens' => 180,
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Lex',
            'description' => 'Lawyer assistant',
            'genre' => 'legal',
            'personality' => 'friendly',
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $otherChat = Chat::create(['profile_id' => $profile->id]);

        for ($i = 1; $i <= 7; $i++) {
            Message::create([
                'profile_id' => $profile->id,
                'chat_id' => $chat->id,
                'text' => "prior-message-{$i}",
                'type' => $i % 2 === 0 ? 'answer' : 'question',
                'source' => $i % 2 === 0 ? 'openai' : 'api',
            ]);
        }

        Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $otherChat->id,
            'text' => 'other-chat-message',
            'type' => 'question',
            'source' => 'api',
        ]);

        $currentQuestion = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'current-question',
            'type' => 'question',
            'source' => 'api',
        ]);

        $client = $this->makeClient();
        $client->getAnswer($profile, $currentQuestion->text, $chat->id, $currentQuestion->id);

        Http::assertSent(function ($request) {
            $systemPrompt = $request->data()['messages'][0]['content'];

            return str_contains($systemPrompt, 'Recent chat messages from this chat, oldest to newest')
                && str_contains($systemPrompt, 'prior-message-2')
                && str_contains($systemPrompt, 'prior-message-7')
                && str_contains($systemPrompt, '"role":"assistant"')
                && str_contains($systemPrompt, '"role":"user"')
                && ! str_contains($systemPrompt, 'prior-message-1')
                && ! str_contains($systemPrompt, 'current-question')
                && ! str_contains($systemPrompt, 'other-chat-message');
        });
    }

    #[Test]
    public function it_includes_only_enabled_published_products_and_product_response_rules(): void
    {
        Http::fake([
            'https://fake-openai.test/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => ['content' => '{"answer":"La creatina puede complementar tu plan.","media_request":false,"media_action":"none","media_ids":[],"product_request":true,"product_action":"show","product_ids":[1],"constraints":{"include_providers":[],"exclude_providers":[],"include_source_types":[],"exclude_source_types":[],"require_unseen":false}}'],
                        'finish_reason' => 'stop',
                    ],
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($user)->create([
            'locale' => 'es',
            'products_enabled' => true,
        ]);
        $service = app(ProfileProductService::class);
        $published = $service->create($profile, $user, [
            'name' => 'Creatina Monohidratada',
            'description' => 'Suplemento de creatina para complementar el entrenamiento.',
            'image_url' => 'https://images.example.com/creatine.jpg',
            'destination_type' => 'external_url',
            'destination_url' => 'https://shop.example.com/creatine',
            'status' => 'published',
        ]);
        $service->create($profile, $user, [
            'name' => 'Producto interno',
            'description' => 'Este borrador no puede recomendarse.',
            'image_url' => 'https://images.example.com/draft.jpg',
            'destination_type' => 'external_url',
            'destination_url' => 'https://shop.example.com/draft',
            'status' => 'draft',
        ]);
        $notebook = $service->create($profile, $user, [
            'name' => 'Cuaderno universitario',
            'description' => 'Formato 21 x 29.7 cm, 100 hojas, precio $28.000.',
            'image_url' => 'https://images.example.com/notebook.jpg',
            'destination_type' => 'external_url',
            'destination_url' => 'https://shop.example.com/notebook',
            'status' => 'published',
        ]);

        $this->makeClient()->getAnswer($profile, '¿Cuál producto es más grande o más barato?');

        Http::assertSent(function ($request) use ($notebook, $published): bool {
            $prompt = $request->data()['messages'][0]['content'];

            return str_contains($prompt, 'Published products available for this conversation')
                && str_contains($prompt, '"id":'.$published->id)
                && str_contains($prompt, '"name":"Creatina Monohidratada"')
                && str_contains($prompt, '"id":'.$notebook->id)
                && str_contains($prompt, 'Formato 21 x 29.7 cm, 100 hojas, precio $28.000.')
                && ! str_contains($prompt, 'Producto interno')
                && str_contains($prompt, 'Do not force a sale into unrelated conversation')
                && str_contains($prompt, '"product_action" as "none" or "show"')
                && str_contains($prompt, 'infer the comparison from explicit values and specifications')
                && str_contains($prompt, 'one compact semicolon-separated sentence')
                && str_contains($prompt, 'Never return a comparison that ends mid-sentence')
                && str_contains($prompt, 'product answers may use up to 400 characters')
                && str_contains($prompt, 'attach every directly compared product')
                && str_contains($prompt, 'Do not invent ingredients, prices, discounts')
                && str_contains($prompt, 'implied benefits such as improving performance, recovery');
        });
    }

    #[Test]
    public function it_marks_the_most_recently_shown_instagram_media_in_the_system_prompt(): void
    {
        Log::spy();

        Http::fake([
            'https://fake-openai.test/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => ['content' => '{"answer":"Fue en Amsterdam.","media_action":"none","media_ids":[]}'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 120,
                    'completion_tokens' => 60,
                    'total_tokens' => 180,
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Lex',
            'description' => 'Lawyer assistant',
            'genre' => 'legal',
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
        $rome = ProfileIntegrationMedia::create([
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
            'taken_at' => now()->subDay(),
        ]);
        $amsterdam = ProfileIntegrationMedia::create([
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

        Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Aquí tienes una foto en Roma.',
            'type' => 'answer',
            'source' => 'openai',
            'data' => ['media' => [['id' => $rome->id]]],
        ]);
        Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Aquí tienes otra foto en Amsterdam.',
            'type' => 'answer',
            'source' => 'openai',
            'data' => ['media' => [['id' => $amsterdam->id]]],
        ]);
        $currentQuestion = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => '¿Dónde fue esa foto?',
            'type' => 'question',
            'source' => 'api',
        ]);

        $client = $this->makeClient();
        $client->getAnswer($profile, $currentQuestion->text, $chat->id, $currentQuestion->id);

        Http::assertSent(function ($request) use ($amsterdam) {
            $systemPrompt = $request->data()['messages'][0]['content'];
            $recentlyShownPosition = strpos($systemPrompt, 'Most recently shown media item in this chat:');

            return $recentlyShownPosition !== false
                && str_contains(substr($systemPrompt, $recentlyShownPosition), '"id":'.$amsterdam->id)
                && str_contains(substr($systemPrompt, $recentlyShownPosition), '"observation":"Amsterdam"')
                && str_contains($systemPrompt, 'Infer from the current message and recent chat whether the visitor is asking for media')
                && str_contains($systemPrompt, 'For references like "esa foto"');
        });
    }

    #[Test]
    public function it_includes_all_selected_media_and_structured_media_constraints_in_the_system_prompt(): void
    {
        Log::spy();

        Http::fake([
            'https://fake-openai.test/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => ['content' => '{"answer":"Te comparto una foto.","media_action":"show","media_ids":[]}'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 120,
                    'completion_tokens' => 60,
                    'total_tokens' => 180,
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Lex',
            'description' => 'Lawyer assistant',
            'genre' => 'legal',
            'personality' => 'friendly',
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
            'observation' => 'FB note',
            'selected' => true,
            'taken_at' => now()->subDay(),
        ]);

        $client = $this->makeClient();
        $client->getAnswer($profile, 'Muéstrame una foto que no sea de Instagram');

        Http::assertSent(function ($request) use ($facebookMedia, $instagramMedia) {
            $systemPrompt = $request->data()['messages'][0]['content'];

            return str_contains($systemPrompt, 'Selected media available for visitor conversations before constraints')
                && str_contains($systemPrompt, '"id":'.$facebookMedia->id)
                && str_contains($systemPrompt, '"provider_label":"Facebook"')
                && str_contains($systemPrompt, '"source_type":"social_network"')
                && str_contains($systemPrompt, '"id":'.$instagramMedia->id)
                && str_contains($systemPrompt, '"provider_label":"Instagram"')
                && str_contains($systemPrompt, 'fill constraints from the meaning of the request')
                && str_contains($systemPrompt, 'Apply constraints before choosing media_ids')
                && str_contains($systemPrompt, 'never invent, intensify, or add sexual details')
                && str_contains($systemPrompt, 'Keep the tone neutral')
                && str_contains($systemPrompt, '"constraints" as an object')
                && str_contains($systemPrompt, 'The answer string must be 200 characters or fewer');
        });
    }

    #[Test]
    public function it_includes_authoritative_profile_networks_in_the_system_prompt(): void
    {
        Log::spy();

        Http::fake([
            'https://fake-openai.test/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => ['content' => 'My Instagram is https://www.instagram.com/lifetaps/.'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 120,
                    'completion_tokens' => 60,
                    'total_tokens' => 180,
                ],
            ], 200),
        ]);

        $profile = $this->makeProfile();
        $profile->data = array_merge($profile->data, [
            'networks' => [
                ['name' => 'linkedin', 'url' => 'https://www.linkedin.com/in/old-link/'],
            ],
        ]);
        $profile->networks = [
            'instagram' => 'https://www.instagram.com/lifetaps/',
            'github' => 'https://github.com/aosmorac',
            'linkedin' => 'https://www.linkedin.com/in/abelmoreno/',
        ];

        $client = $this->makeClient();
        $client->getAnswer($profile, 'What is your Instagram?');

        Http::assertSent(function ($request) {
            $systemPrompt = $request->data()['messages'][0]['content'];

            return str_contains($systemPrompt, 'Public social links (authoritative):')
                && str_contains($systemPrompt, 'Instagram: https://www.instagram.com/lifetaps/')
                && str_contains($systemPrompt, 'GitHub: https://github.com/aosmorac')
                && str_contains($systemPrompt, 'LinkedIn: https://www.linkedin.com/in/abelmoreno/')
                && str_contains($systemPrompt, 'answer using these exact links')
                && str_contains($systemPrompt, 'do not say you do not have them');
        });
    }

    #[Test]
    public function it_returns_failed_chat_answer_when_api_returns_error(): void
    {
        Log::spy();

        Http::fake([
            'https://fake-openai.test/v1/chat/completions' => Http::response([
                'error' => [
                    'message' => 'Something went wrong',
                ],
            ], 500),
        ]);

        $client = $this->makeClient();
        $profile = $this->makeProfile();

        $answer = $client->getAnswer($profile, 'Test message');

        $this->assertInstanceOf(ChatAIAnswer::class, $answer);
        $this->assertSame('failed', $answer->status);
        $this->assertFalse($answer->hasAnswer());
        $this->assertNull($answer->confidence);
        $this->assertEquals('Something went wrong', $answer->response['error']['message']);

        Http::assertSentCount(1);
    }

    #[Test]
    public function it_retries_transient_chat_request_exceptions(): void
    {
        Log::spy();

        $attempts = 0;

        Http::fake(function () use (&$attempts) {
            $attempts++;

            if ($attempts < 3) {
                throw new \RuntimeException('Could not resolve host: api.openai.com');
            }

            return Http::response([
                'choices' => [
                    [
                        'message' => ['content' => 'Recovered response.'],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 50,
                    'completion_tokens' => 20,
                    'total_tokens' => 70,
                ],
            ], 200);
        });

        $client = $this->makeClient(retryAttempts: 3);
        $profile = $this->makeProfile();

        $answer = $client->getAnswer($profile, 'Test message');

        $this->assertSame(3, $attempts);
        $this->assertSame('success', $answer->status);
        $this->assertSame('Recovered response.', $answer->answer);
    }

    #[Test]
    public function it_returns_error_chat_answer_when_request_throws_exception(): void
    {
        Log::spy();

        Http::fake(function () {
            throw new \RuntimeException('Network unreachable');
        });

        $client = $this->makeClient();
        $profile = $this->makeProfile();

        $answer = $client->getAnswer($profile, 'Test message');

        $this->assertInstanceOf(ChatAIAnswer::class, $answer);
        $this->assertSame('error', $answer->status);
        $this->assertEquals(['error' => 'Network unreachable'], $answer->response);
    }

    #[Test]
    public function it_returns_failed_transcription_when_audio_file_missing(): void
    {
        $client = $this->makeClient();

        $transcription = $client->getTextFromAudio('/tmp/non-existent-file.wav');

        $this->assertInstanceOf(ChatAITextFromAudio::class, $transcription);
        $this->assertSame('failed', $transcription->status);
        $this->assertEquals(['error' => 'Audio file not found'], $transcription->response);
    }

    #[Test]
    public function it_returns_successful_transcription_response(): void
    {
        Log::spy();

        $audioPath = tempnam(sys_get_temp_dir(), 'audio_');
        file_put_contents($audioPath, 'fake audio content');

        Http::fake([
            'https://fake-openai.test/v1/audio/transcriptions' => Http::response([
                'text' => 'This is the transcription of your audio sample.',
                'language' => 'en',
                'duration' => 5.4,
            ], 200),
        ]);

        $client = $this->makeClient();
        $transcription = $client->getTextFromAudio($audioPath);

        $this->assertInstanceOf(ChatAITextFromAudio::class, $transcription);
        $this->assertTrue($transcription->isSuccessful());
        $this->assertSame('This is the transcription of your audio sample.', $transcription->text);
        $this->assertSame('success', $transcription->status);
        $this->assertSame('https://fake-openai.test/v1/audio/transcriptions', $transcription->requestUrl);
        $this->assertSame('en', $transcription->detectedLanguage);
        $this->assertSame(5.4, $transcription->duration);
        $this->assertSame(0.8, $transcription->confidence);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://fake-openai.test/v1/audio/transcriptions';
        });

        unlink($audioPath);
    }

    #[Test]
    public function it_returns_failed_transcription_when_api_returns_error(): void
    {
        Log::spy();

        $audioPath = tempnam(sys_get_temp_dir(), 'audio_');
        file_put_contents($audioPath, 'fake audio content');

        Http::fake([
            'https://fake-openai.test/v1/audio/transcriptions' => Http::response([
                'error' => [
                    'message' => 'transcription failed',
                ],
            ], 422),
        ]);

        $client = $this->makeClient();
        $transcription = $client->getTextFromAudio($audioPath);

        $this->assertInstanceOf(ChatAITextFromAudio::class, $transcription);
        $this->assertSame('failed', $transcription->status);
        $this->assertEquals('transcription failed', $transcription->response['error']['message']);
        $this->assertNull($transcription->confidence);

        unlink($audioPath);
    }

    #[Test]
    public function it_returns_error_transcription_when_request_throws_exception(): void
    {
        Log::spy();

        $audioPath = tempnam(sys_get_temp_dir(), 'audio_');
        file_put_contents($audioPath, 'fake audio content');

        Http::fake(function () {
            throw new \RuntimeException('Audio service down');
        });

        $client = $this->makeClient();
        $transcription = $client->getTextFromAudio($audioPath);

        $this->assertInstanceOf(ChatAITextFromAudio::class, $transcription);
        $this->assertSame('error', $transcription->status);
        $this->assertEquals(['error' => 'Audio service down'], $transcription->response);

        unlink($audioPath);
    }

    #[Test]
    public function it_adjusts_transcription_confidence_for_short_texts(): void
    {
        Log::spy();

        $audioPath = tempnam(sys_get_temp_dir(), 'audio_');
        file_put_contents($audioPath, 'fake audio content');

        Http::fake([
            'https://fake-openai.test/v1/audio/transcriptions' => Http::response([
                'text' => 'Short',
            ], 200),
        ]);

        $client = $this->makeClient();
        $transcription = $client->getTextFromAudio($audioPath);

        $this->assertEqualsWithDelta(0.6, $transcription->confidence, 0.0001);

        unlink($audioPath);
    }
}
