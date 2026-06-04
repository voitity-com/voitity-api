<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\ChatAIService\OpenAI;

use App\Classes\ChatAIService\ChatAIAnswer;
use App\Classes\ChatAIService\ChatAITextFromAudio;
use App\Classes\ChatAIService\OpenAI\OpenAIClient;
use App\Models\Chat;
use App\Models\Message;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OpenAIClientTest extends TestCase
{
    private function makeClient(): OpenAIClient
    {
        return new OpenAIClient(
            apiKey: 'test-api-key',
            baseUrl: 'https://fake-openai.test/v1',
            defaultModel: 'gpt-4o-mini',
            whisperModel: 'whisper-1'
        );
    }

    private function makeProfile(): Profile
    {
        $profile = new Profile();
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
                && $payload['messages'][0]['role'] === 'system'
                && str_starts_with($systemPrompt, 'Your name is: ' . $profile->name)
                && str_contains($systemPrompt, '. ' . $profile->description)
                && str_contains($systemPrompt, 'Your personality is ' . $profile->personality)
                && str_contains($systemPrompt, 'Profile data:')
                && str_contains($systemPrompt, '"company":"Voitity"')
                && str_contains($systemPrompt, 'Only answer using the information in this prompt')
                && str_contains($systemPrompt, 'you do not have that information at this moment')
                && str_contains($systemPrompt, 'Make the conversation feel natural and progressive')
                && str_contains($systemPrompt, 'decide whether a short or detailed answer is appropriate')
                && str_contains($systemPrompt, 'Do not reveal all profile information at once')
                && !str_contains($systemPrompt, 'Provide legal advice')
                && !str_contains($systemPrompt, 'Always maintain a warm, approachable tone');
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
                && !str_contains($systemPrompt, 'prior-message-1')
                && !str_contains($systemPrompt, 'current-question')
                && !str_contains($systemPrompt, 'other-chat-message');
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
