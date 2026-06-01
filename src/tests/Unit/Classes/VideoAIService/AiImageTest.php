<?php

namespace Tests\Unit\Classes\VideoAIService;

use App\Classes\VideoAIService\AiImage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiImageTest extends TestCase
{
    #[Test]
    public function it_can_be_converted_to_array_with_expected_structure(): void
    {
        $image = new AiImage(
            id: '21b1e8e7-4d22-4178-afa9-49da8239946e',
            createdAt: '2026-05-29T16:41:47.983Z',
            status: 'SUCCEEDED',
            output: ['https://example.com/image.png'],
            response: ['provider' => 'runway'],
            requestUrl: 'https://api.dev.runwayml.com/v1/tasks/123'
        );

        $this->assertSame([
            'id' => '21b1e8e7-4d22-4178-afa9-49da8239946e',
            'createdAt' => '2026-05-29T16:41:47.983Z',
            'status' => 'SUCCEEDED',
            'output' => ['https://example.com/image.png'],
        ], $image->toArray());
    }

    #[Test]
    public function it_detects_status_and_output_url(): void
    {
        $image = new AiImage(status: 'SUCCEEDED', output: ['https://example.com/image.png']);

        $this->assertTrue($image->isSuccessful());
        $this->assertFalse($image->isFailed());
        $this->assertFalse($image->isPending());
        $this->assertSame('https://example.com/image.png', $image->getOutputUrl());
    }

    #[Test]
    public function it_detects_failed_and_pending_statuses(): void
    {
        $failed = new AiImage(status: 'error');
        $pending = new AiImage(status: 'PENDING');

        $this->assertTrue($failed->isFailed());
        $this->assertTrue($pending->isPending());
    }
}
