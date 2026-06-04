<?php

namespace Tests\Unit\Classes\ChatAIService;

use App\Classes\ChatAIService\AnswerBuilder;
use App\Classes\ChatAIService\ChatAIAnswer;
use App\Classes\ChatAIService\ChatAIClient;
use App\Classes\VoiceService\VoiceClient;
use App\Classes\VoiceService\VoiceClientGeneratedAudio;
use App\Classes\VoiceService\VoiceManager;
use App\Models\Chat;
use App\Models\Message;
use App\Models\Profile;
use App\Models\User;
use App\Models\Voice;
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
    }

    public function test_get_answer_without_active_voice_returns_null_audio(): void
    {
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

    public function test_get_answer_handles_voice_driver_failure(): void
    {
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
}
