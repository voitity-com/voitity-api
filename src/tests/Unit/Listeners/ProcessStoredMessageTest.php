<?php

namespace Tests\Unit\Listeners;

use App\Classes\ChatAIService\AnswerBuilder;
use App\Classes\ChatAIService\AnswerResponse;
use App\Classes\ChatAIService\ChatAIAnswer;
use App\Events\MessageStored;
use App\Listeners\ProcessStoredMessage;
use App\Models\Chat;
use App\Models\Message;
use App\Models\Profile;
use App\Models\User;
use Mockery;
use Tests\TestCase;

class ProcessStoredMessageTest extends TestCase
{

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_handle_processes_question_and_sets_flags(): void
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
            'text' => 'Question?',
            'type' => 'question',
            'source' => 'api',
            'data' => [],
        ]);

        $answerMessage = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Answer!',
            'type' => 'answer',
            'source' => 'openai',
        ]);

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: 'Answer!',
            status: 'success'
        );

        $answerResponse = new AnswerResponse($answerMessage, $chatAiAnswer);

        $builder = Mockery::mock(AnswerBuilder::class);
        $builder->shouldReceive('getAnswer')
            ->once()
            ->withArgs(function (Profile $handledProfile, Message $handledMessage) use ($profile, $question) {
                return $handledProfile->is($profile) && $handledMessage->is($question);
            })
            ->andReturn($answerResponse);

        $listener = new ProcessStoredMessage($builder);

        $event = new MessageStored($question);

        $listener->handle($event);

        $question->refresh();

        $this->assertSame($answerResponse, $event->answer);
        $this->assertFalse($question->data['processing']);
        $this->assertArrayHasKey('processed_at', $question->data);
        $this->assertSame($answerMessage->id, $question->data['answer_message_id']);
    }

    public function test_handle_skips_non_question_messages(): void
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

        $message = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Stored answer',
            'type' => 'answer',
            'source' => 'openai',
            'data' => [],
        ]);

        $builder = Mockery::mock(AnswerBuilder::class);
        $builder->shouldReceive('getAnswer')->never();

        $listener = new ProcessStoredMessage($builder);
        $event = new MessageStored($message);

        $listener->handle($event);

        $this->assertNull($event->answer);
    }

    public function test_handle_skips_when_processing_flag_set(): void
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

        $message = Message::create([
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'text' => 'Processing',
            'type' => 'question',
            'source' => 'api',
            'data' => ['processing' => true],
        ]);

        $builder = Mockery::mock(AnswerBuilder::class);
        $builder->shouldReceive('getAnswer')->never();

        $listener = new ProcessStoredMessage($builder);
        $event = new MessageStored($message);

        $listener->handle($event);

        $this->assertNull($event->answer);
    }
}
