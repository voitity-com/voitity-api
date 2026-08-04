<?php

namespace Tests\Unit\Classes;

use App\Classes\ChatAIService\AnswerBuilder;
use App\Classes\ChatAIService\ChatAIAnswer;
use App\Classes\ChatAIService\ChatAIClient;
use App\Classes\ChatAIService\ChatAITextFromAudio;
use App\Classes\VoiceService\VoiceManager;
use App\Models\Chat;
use App\Models\Message;
use App\Models\Profile;
use App\Models\ProfileConversationMessage;
use App\Models\User;
use App\Models\Voice;
use App\Services\ProfileConversationMessageService;
use Illuminate\Support\Facades\Event;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AnswerBuilderConversationMessagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_uses_preconfigured_fallback_text_and_audio_when_ai_cannot_answer(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->create(['user_id' => $user->id]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'chat_id' => $chat->id,
            'data' => [],
            'profile_id' => $profile->id,
            'source' => 'api',
            'text' => 'Cual es su numero de pasaporte?',
            'type' => 'question',
        ]);

        ProfileConversationMessage::factory()->create([
            'audio_format' => 'mp3',
            'audio_source' => ProfileConversationMessage::AUDIO_SOURCE_RECORDED,
            'audio_url' => 'https://audio.test/fallback.mp3',
            'profile_id' => $profile->id,
            'status' => ProfileConversationMessage::STATUS_READY,
            'text' => 'Todavía no tengo ese dato, pero puedo contarte sobre mi trabajo.',
            'type' => ProfileConversationMessage::TYPE_FALLBACK_NO_ANSWER,
        ]);

        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();
        $conversationMessages = new ProfileConversationMessageService($voiceManager);
        $chatClient = new FakeNoAnswerChatClient(new ChatAIAnswer(
            source: 'openai',
            answer: '[[BIGMELO_NO_ANSWER]] No tengo esa información.',
            status: 'completed'
        ));
        $builder = new AnswerBuilder($chatClient, $voiceManager, $conversationMessages);

        $response = $builder->getAnswer($profile, $question);
        $responsePayload = $response->toArray();
        $answer = Message::findOrFail($responsePayload['message_id']);

        $this->assertSame('Todavía no tengo ese dato, pero puedo contarte sobre mi trabajo.', $answer->text);
        $this->assertSame('profile_conversation_message', $answer->source);
        $this->assertSame('https://audio.test/fallback.mp3', $answer->audio);
        $this->assertSame('https://audio.test/fallback.mp3', $responsePayload['audio_url']);
    }

    public function test_fallback_without_audio_does_not_generate_voice_audio_in_chat_response(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->create(['user_id' => $user->id]);
        Voice::factory()->create([
            'active' => true,
            'profile_id' => $profile->id,
            'source' => 'elevenlabs',
            'source_voice_id' => 'voice-123',
            'user_id' => $user->id,
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'chat_id' => $chat->id,
            'data' => [],
            'profile_id' => $profile->id,
            'source' => 'api',
            'text' => 'Que dato privado sabes?',
            'type' => 'question',
        ]);

        ProfileConversationMessage::factory()->create([
            'audio_url' => null,
            'profile_id' => $profile->id,
            'status' => ProfileConversationMessage::STATUS_READY,
            'text' => 'No tengo ese dato todavía.',
            'type' => ProfileConversationMessage::TYPE_FALLBACK_NO_ANSWER,
        ]);

        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();
        $conversationMessages = new ProfileConversationMessageService($voiceManager);
        $chatClient = new FakeNoAnswerChatClient(new ChatAIAnswer(
            source: 'openai',
            answer: '[[BIGMELO_NO_ANSWER]] No tengo esa información.',
            status: 'completed'
        ));
        $builder = new AnswerBuilder($chatClient, $voiceManager, $conversationMessages);

        $responsePayload = $builder->getAnswer($profile, $question)->toArray();
        $answer = Message::findOrFail($responsePayload['message_id']);

        $this->assertSame('No tengo ese dato todavía.', $answer->text);
        $this->assertNull($answer->audio);
        $this->assertNull($responsePayload['audio_url']);
    }

    public function test_profile_voice_setting_prevents_generated_answer_audio(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->create([
            'data' => ['voice_enabled' => false, 'voice_autoplay_enabled' => false],
            'user_id' => $user->id,
        ]);
        Voice::factory()->create([
            'active' => true,
            'profile_id' => $profile->id,
            'source' => 'elevenlabs',
            'source_voice_id' => 'voice-123',
            'user_id' => $user->id,
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'chat_id' => $chat->id,
            'data' => ['request' => ['audio_response_enabled' => true]],
            'profile_id' => $profile->id,
            'source' => 'api',
            'text' => 'Hola',
            'type' => 'question',
        ]);

        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();
        $builder = new AnswerBuilder(
            new FakeNoAnswerChatClient(new ChatAIAnswer(
                source: 'openai',
                answer: 'Respuesta solo texto.',
                status: 'completed'
            )),
            $voiceManager,
            new ProfileConversationMessageService($voiceManager)
        );

        $responsePayload = $builder->getAnswer($profile, $question)->toArray();
        $answer = Message::findOrFail($responsePayload['message_id']);

        $this->assertSame('Respuesta solo texto.', $answer->text);
        $this->assertNull($answer->audio);
        $this->assertNull($responsePayload['audio_url']);
    }

    public function test_muted_request_prevents_generated_answer_audio(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->create([
            'data' => ['voice_enabled' => true, 'voice_autoplay_enabled' => true],
            'user_id' => $user->id,
        ]);
        Voice::factory()->create([
            'active' => true,
            'profile_id' => $profile->id,
            'source' => 'elevenlabs',
            'source_voice_id' => 'voice-123',
            'user_id' => $user->id,
        ]);
        $chat = Chat::create(['profile_id' => $profile->id]);
        $question = Message::create([
            'chat_id' => $chat->id,
            'data' => ['request' => ['audio_response_enabled' => false]],
            'profile_id' => $profile->id,
            'source' => 'api',
            'text' => 'Hola',
            'type' => 'question',
        ]);

        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->never();
        $builder = new AnswerBuilder(
            new FakeNoAnswerChatClient(new ChatAIAnswer(
                source: 'openai',
                answer: 'Respuesta sin audio por mute.',
                status: 'completed'
            )),
            $voiceManager,
            new ProfileConversationMessageService($voiceManager)
        );

        $responsePayload = $builder->getAnswer($profile, $question)->toArray();
        $answer = Message::findOrFail($responsePayload['message_id']);

        $this->assertSame('Respuesta sin audio por mute.', $answer->text);
        $this->assertNull($answer->audio);
        $this->assertNull($responsePayload['audio_url']);
    }
}

class FakeNoAnswerChatClient implements ChatAIClient
{
    public function __construct(private readonly ChatAIAnswer $answer) {}

    public function getAnswer(
        Profile $profile,
        string $message,
        ?int $chatId = null,
        ?int $currentMessageId = null
    ): ChatAIAnswer {
        return $this->answer;
    }

    public function getTextFromAudio(string $audioPath): ChatAITextFromAudio
    {
        throw new RuntimeException('Audio transcription is not used in this test.');
    }
}
