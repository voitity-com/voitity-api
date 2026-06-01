<?php

namespace Tests\Unit\Classes\VideoAIService;

use App\Classes\VideoAIService\AiVideo;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiVideoTest extends TestCase
{
    #[Test]
    public function it_can_be_converted_to_array_with_expected_structure(): void
    {
        $video = new AiVideo(
            id: '21b1e8e7-4d22-4178-afa9-49da8239946e',
            createdAt: '2026-05-29T16:41:47.983Z',
            status: 'SUCCEEDED',
            output: ['https://example.com/video.mp4'],
            response: ['provider' => 'runway'],
            requestUrl: 'https://api.dev.runwayml.com/v1/tasks/123'
        );

        $this->assertSame([
            'id' => '21b1e8e7-4d22-4178-afa9-49da8239946e',
            'createdAt' => '2026-05-29T16:41:47.983Z',
            'status' => 'SUCCEEDED',
            'output' => ['https://example.com/video.mp4'],
        ], $video->toArray());
    }

    #[Test]
    public function it_detects_status_and_output_url(): void
    {
        $video = new AiVideo(status: 'completed', output: ['https://example.com/video.mp4']);

        $this->assertTrue($video->isSuccessful());
        $this->assertFalse($video->isFailed());
        $this->assertFalse($video->isPending());
        $this->assertSame('https://example.com/video.mp4', $video->getOutputUrl());
    }

    #[Test]
    public function it_detects_failed_and_pending_statuses(): void
    {
        $failed = new AiVideo(status: 'failed');
        $pending = new AiVideo(status: 'processing');

        $this->assertTrue($failed->isFailed());
        $this->assertTrue($pending->isPending());
    }
}
