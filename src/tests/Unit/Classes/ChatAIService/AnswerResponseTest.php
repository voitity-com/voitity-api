<?php

namespace Tests\Unit\Classes\ChatAIService;

use App\Classes\ChatAIService\AnswerResponse;
use App\Classes\ChatAIService\ChatAIAnswer;
use App\Models\Message;
use PHPUnit\Framework\TestCase;

class AnswerResponseTest extends TestCase
{
    public function test_to_array_uses_existing_chat_ai_payload(): void
    {
        $message = new Message([
            'chat_id' => 1,
            'text' => 'response text',
            'audio' => null,
            'source' => 'openai',
            'data' => [
                'chat_ai' => ['answer' => 'stored answer'],
                'meta' => ['foo' => 'bar'],
            ],
        ]);
        $message->id = 10;

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: 'builder answer',
            status: 'success'
        );

        $response = new AnswerResponse($message, $chatAiAnswer);

        $payload = $response->toArray();

        $this->assertSame('stored answer', $payload['data']['chat_ai']['answer']);
        $this->assertSame('response text', $payload['text']);
        $this->assertNull($payload['audio_url']);
    }

    public function test_to_array_injects_chat_ai_payload_when_missing(): void
    {
        $message = new Message([
            'chat_id' => 2,
            'text' => 'answer',
            'audio' => null,
            'source' => 'openai',
        ]);
        $message->id = 11;

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: 'computed answer',
            status: 'success',
            response: ['raw' => true]
        );

        $response = new AnswerResponse($message, $chatAiAnswer);

        $payload = $response->toArray();

        $this->assertSame('computed answer', $payload['data']['chat_ai']['answer']);
        $this->assertSame(['raw' => true], $payload['data']['chat_ai']['response']);
    }

    public function test_to_array_prefers_message_audio_over_payload(): void
    {
        $message = new Message([
            'chat_id' => 3,
            'text' => 'audio provided',
            'audio' => 'https://cdn.example.com/audio/message.mp3',
            'source' => 'openai',
        ]);
        $message->id = 12;

        $chatAiAnswer = new ChatAIAnswer(
            source: 'openai',
            answer: 'audio answer',
            status: 'success'
        );

        $response = new AnswerResponse(
            $message,
            $chatAiAnswer,
            ['audio_url' => 'https://cdn.example.com/audio/payload.mp3']
        );

        $payload = $response->toArray();

        $this->assertSame('https://cdn.example.com/audio/message.mp3', $payload['audio_url']);
    }
}
