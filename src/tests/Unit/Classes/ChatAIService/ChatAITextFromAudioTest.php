<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\ChatAIService;

use App\Classes\ChatAIService\ChatAITextFromAudio;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChatAITextFromAudioTest extends TestCase
{
    #[Test]
    public function it_sets_default_values_when_not_provided(): void
    {
        $transcription = new ChatAITextFromAudio(
            source: 'openai',
            audioPath: '/tmp/audio.mp3'
        );

        $this->assertSame('openai', $transcription->source);
        $this->assertSame('/tmp/audio.mp3', $transcription->audioPath);
        $this->assertSame('', $transcription->text);
        $this->assertSame('pending', $transcription->status);
        $this->assertSame([], $transcription->response);
        $this->assertNull($transcription->requestUrl);
        $this->assertNull($transcription->confidence);
        $this->assertNull($transcription->detectedLanguage);
        $this->assertNull($transcription->duration);
    }

    #[Test]
    public function it_stores_all_constructor_arguments(): void
    {
        $responseData = ['id' => 'transcription-1'];

        $transcription = new ChatAITextFromAudio(
            source: 'openai',
            audioPath: '/tmp/audio.mp3',
            text: 'Hello world transcription',
            status: 'success',
            response: $responseData,
            requestUrl: 'https://api.openai.com/v1/audio/transcriptions',
            confidence: 0.82,
            detectedLanguage: 'en',
            duration: 12.5
        );

        $this->assertSame('Hello world transcription', $transcription->text);
        $this->assertSame('success', $transcription->status);
        $this->assertSame($responseData, $transcription->response);
        $this->assertSame('https://api.openai.com/v1/audio/transcriptions', $transcription->requestUrl);
        $this->assertSame(0.82, $transcription->confidence);
        $this->assertSame('en', $transcription->detectedLanguage);
        $this->assertSame(12.5, $transcription->duration);
    }

    #[Test]
    public function it_detects_success_states(): void
    {
        foreach (['completed', 'success'] as $status) {
            $transcription = new ChatAITextFromAudio('openai', '/tmp/audio.mp3', status: $status);
            $this->assertTrue($transcription->isSuccessful(), "Failed asserting success for status '{$status}'");
        }

        foreach (['pending', 'processing', 'failed', 'error'] as $status) {
            $transcription = new ChatAITextFromAudio('openai', '/tmp/audio.mp3', status: $status);
            $this->assertFalse($transcription->isSuccessful(), "Unexpected success for status '{$status}'");
        }
    }

    #[Test]
    public function it_detects_failed_states(): void
    {
        foreach (['failed', 'error'] as $status) {
            $transcription = new ChatAITextFromAudio('openai', '/tmp/audio.mp3', status: $status);
            $this->assertTrue($transcription->isFailed(), "Failed asserting failure for status '{$status}'");
        }

        foreach (['pending', 'processing', 'completed', 'success'] as $status) {
            $transcription = new ChatAITextFromAudio('openai', '/tmp/audio.mp3', status: $status);
            $this->assertFalse($transcription->isFailed(), "Unexpected failure for status '{$status}'");
        }
    }

    #[Test]
    public function it_detects_pending_states(): void
    {
        foreach (['pending', 'processing'] as $status) {
            $transcription = new ChatAITextFromAudio('openai', '/tmp/audio.mp3', status: $status);
            $this->assertTrue($transcription->isPending(), "Failed asserting pending for status '{$status}'");
        }

        foreach (['completed', 'success', 'failed', 'error'] as $status) {
            $transcription = new ChatAITextFromAudio('openai', '/tmp/audio.mp3', status: $status);
            $this->assertFalse($transcription->isPending(), "Unexpected pending for status '{$status}'");
        }
    }

    #[Test]
    public function it_checks_if_text_has_content(): void
    {
        $emptyText = new ChatAITextFromAudio('openai', '/tmp/audio.mp3', text: '');
        $this->assertFalse($emptyText->hasText());

        $withText = new ChatAITextFromAudio('openai', '/tmp/audio.mp3', text: 'Transcribed content');
        $this->assertTrue($withText->hasText());
    }

    #[Test]
    public function it_reports_word_count(): void
    {
        $transcription = new ChatAITextFromAudio('openai', '/tmp/audio.mp3', text: 'Hello there general Kenobi');
        $this->assertSame(4, $transcription->getWordCount());
    }

    #[Test]
    public function it_converts_to_array(): void
    {
        $transcription = new ChatAITextFromAudio(
            source: 'openai',
            audioPath: '/tmp/audio.mp3',
            text: 'Hola mundo',
            status: 'success',
            response: ['segments' => []],
            requestUrl: 'https://api.openai.com/v1/audio/transcriptions',
            confidence: 0.77,
            detectedLanguage: 'es',
            duration: 8.4
        );

        $this->assertSame([
            'source' => 'openai',
            'audio_path' => '/tmp/audio.mp3',
            'text' => 'Hola mundo',
            'status' => 'success',
            'request_url' => 'https://api.openai.com/v1/audio/transcriptions',
            'response' => ['segments' => []],
            'confidence' => 0.77,
            'detected_language' => 'es',
            'duration' => 8.4,
            'word_count' => 2,
        ], $transcription->toArray());
    }
}
