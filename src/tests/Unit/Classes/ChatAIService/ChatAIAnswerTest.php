<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\ChatAIService;

use App\Classes\ChatAIService\ChatAIAnswer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChatAIAnswerTest extends TestCase
{
    #[Test]
    public function it_sets_default_values_when_not_provided(): void
    {
        $answer = new ChatAIAnswer(source: 'openai');

        $this->assertSame('openai', $answer->source);
        $this->assertSame('', $answer->answer);
        $this->assertSame('pending', $answer->status);
        $this->assertSame([], $answer->response);
        $this->assertNull($answer->requestUrl);
        $this->assertNull($answer->confidence);
    }

    #[Test]
    public function it_stores_all_constructor_arguments(): void
    {
        $responseData = ['id' => 'choice-1'];

        $answer = new ChatAIAnswer(
            source: 'openai',
            answer: 'Hello world',
            status: 'success',
            response: $responseData,
            requestUrl: 'https://api.openai.com/v1/chat/completions',
            confidence: 0.75
        );

        $this->assertSame('Hello world', $answer->answer);
        $this->assertSame('success', $answer->status);
        $this->assertSame($responseData, $answer->response);
        $this->assertSame('https://api.openai.com/v1/chat/completions', $answer->requestUrl);
        $this->assertSame(0.75, $answer->confidence);
    }

    #[Test]
    public function it_detects_success_states(): void
    {
        $successStatuses = ['completed', 'success'];

        foreach ($successStatuses as $status) {
            $answer = new ChatAIAnswer(source: 'openai', status: $status);
            $this->assertTrue($answer->isSuccessful(), "Failed asserting success for status '{$status}'");
        }

        $nonSuccessStatuses = ['pending', 'processing', 'failed', 'error'];
        foreach ($nonSuccessStatuses as $status) {
            $answer = new ChatAIAnswer(source: 'openai', status: $status);
            $this->assertFalse($answer->isSuccessful(), "Unexpected success for status '{$status}'");
        }
    }

    #[Test]
    public function it_detects_failed_states(): void
    {
        $failedStatuses = ['failed', 'error'];
        foreach ($failedStatuses as $status) {
            $answer = new ChatAIAnswer(source: 'openai', status: $status);
            $this->assertTrue($answer->isFailed(), "Failed asserting failure for status '{$status}'");
        }

        $nonFailedStatuses = ['pending', 'processing', 'completed', 'success'];
        foreach ($nonFailedStatuses as $status) {
            $answer = new ChatAIAnswer(source: 'openai', status: $status);
            $this->assertFalse($answer->isFailed(), "Unexpected failure for status '{$status}'");
        }
    }

    #[Test]
    public function it_detects_pending_states(): void
    {
        $pendingStatuses = ['pending', 'processing'];
        foreach ($pendingStatuses as $status) {
            $answer = new ChatAIAnswer(source: 'openai', status: $status);
            $this->assertTrue($answer->isPending(), "Failed asserting pending for status '{$status}'");
        }

        $nonPendingStatuses = ['completed', 'success', 'failed', 'error'];
        foreach ($nonPendingStatuses as $status) {
            $answer = new ChatAIAnswer(source: 'openai', status: $status);
            $this->assertFalse($answer->isPending(), "Unexpected pending for status '{$status}'");
        }
    }

    #[Test]
    public function it_checks_if_answer_has_content(): void
    {
        $emptyAnswer = new ChatAIAnswer(source: 'openai', answer: '');
        $this->assertFalse($emptyAnswer->hasAnswer());

        $withAnswer = new ChatAIAnswer(source: 'openai', answer: 'Ready to help!');
        $this->assertTrue($withAnswer->hasAnswer());
    }

    #[Test]
    public function it_converts_to_array(): void
    {
        $answer = new ChatAIAnswer(
            source: 'openai',
            answer: 'Hola',
            status: 'success',
            response: ['choice' => 0],
            requestUrl: 'https://api.openai.com/v1/chat/completions',
            confidence: 0.9
        );

        $this->assertSame([
            'source' => 'openai',
            'answer' => 'Hola',
            'status' => 'success',
            'request_url' => 'https://api.openai.com/v1/chat/completions',
            'response' => ['choice' => 0],
            'confidence' => 0.9,
        ], $answer->toArray());
    }
}
