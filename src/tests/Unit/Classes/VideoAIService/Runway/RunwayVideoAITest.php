<?php

namespace Tests\Unit\Classes\VideoAIService\Runway;

use App\Classes\VideoAIService\Runway\RunwayVideoAI;
use App\Classes\VideoAIService\VideoAIImage;
use App\Classes\VideoAIService\VideoAIVideo;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RunwayVideoAITest extends TestCase
{
    #[Test]
    public function it_creates_image_with_reference_image_payload(): void
    {
        Http::fake([
            'https://runway.test/v1/text_to_image' => Http::response([
                'id' => 'image-task-id',
            ], 200),
        ]);

        $client = $this->client();
        $result = $client->createImage('https://example.com/source.png', 'Generate a clean portrait', '1360:768');

        $this->assertInstanceOf(VideoAIImage::class, $result);
        $this->assertSame('image-task-id', $result->id);
        $this->assertSame('PENDING', $result->status);
        $this->assertSame([], $result->output);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://runway.test/v1/text_to_image'
                && $request->hasHeader('Authorization', 'Bearer test-api-key')
                && $request->hasHeader('X-Runway-Version', '2024-11-06')
                && $data['model'] === 'gen4_image'
                && $data['promptText'] === 'Generate a clean portrait'
                && $data['referenceImages'][0]['uri'] === 'https://example.com/source.png'
                && $data['referenceImages'][0]['tag'] === 'base_image'
                && $data['ratio'] === '1360:768';
        });
    }

    #[Test]
    public function it_creates_video_with_prompt_image_payload_and_default_duration(): void
    {
        Http::fake([
            'https://runway.test/v1/image_to_video' => Http::response([
                'id' => 'video-task-id',
            ], 200),
        ]);

        $client = $this->client();
        $result = $client->createVideo('https://example.com/generated.png', 'Subtle loop motion', '1280:720');

        $this->assertInstanceOf(VideoAIVideo::class, $result);
        $this->assertSame('video-task-id', $result->id);
        $this->assertSame('PENDING', $result->status);

        Http::assertSent(function (Request $request) {
            $data = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://runway.test/v1/image_to_video'
                && $request->hasHeader('Authorization', 'Bearer test-api-key')
                && $request->hasHeader('X-Runway-Version', '2024-11-06')
                && $data['model'] === 'gen4.5'
                && $data['promptImage'] === 'https://example.com/generated.png'
                && $data['promptText'] === 'Subtle loop motion'
                && $data['ratio'] === '1280:720'
                && $data['duration'] === 5;
        });
    }

    #[Test]
    public function it_uses_square_default_ratio_when_creating_image_without_ratio(): void
    {
        Http::fake([
            'https://runway.test/v1/text_to_image' => Http::response([
                'id' => 'image-task-id',
            ], 200),
        ]);

        $this->client()->createImage('https://example.com/source.png', 'Generate a clean portrait');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://runway.test/v1/text_to_image'
                && $request->data()['ratio'] === '1024:1024';
        });
    }

    #[Test]
    public function it_uses_square_default_ratio_when_creating_video_without_ratio(): void
    {
        Http::fake([
            'https://runway.test/v1/image_to_video' => Http::response([
                'id' => 'video-task-id',
            ], 200),
        ]);

        $this->client()->createVideo('https://example.com/generated.png', 'Subtle loop motion');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://runway.test/v1/image_to_video'
                && $request->data()['ratio'] === '960:960';
        });
    }

    #[Test]
    public function it_gets_image_task_by_source_id(): void
    {
        Http::fake([
            'https://runway.test/v1/tasks/image-task-id' => Http::response([
                'id' => 'image-task-id',
                'createdAt' => '2026-05-29T16:41:47.983Z',
                'status' => 'SUCCEEDED',
                'output' => ['https://example.com/image.png'],
            ], 200),
        ]);

        $result = $this->client()->getImage('image-task-id');

        $this->assertInstanceOf(VideoAIImage::class, $result);
        $this->assertSame('image-task-id', $result->id);
        $this->assertSame('2026-05-29T16:41:47.983Z', $result->createdAt);
        $this->assertSame('SUCCEEDED', $result->status);
        $this->assertSame(['https://example.com/image.png'], $result->output);
        $this->assertTrue($result->isSuccessful());
    }

    #[Test]
    public function it_gets_video_task_by_source_id(): void
    {
        Http::fake([
            'https://runway.test/v1/tasks/video-task-id' => Http::response([
                'id' => 'video-task-id',
                'createdAt' => '2026-05-29T16:41:47.983Z',
                'status' => 'SUCCEEDED',
                'output' => ['https://example.com/video.mp4'],
            ], 200),
        ]);

        $result = $this->client()->getVideo('video-task-id');

        $this->assertInstanceOf(VideoAIVideo::class, $result);
        $this->assertSame('video-task-id', $result->id);
        $this->assertSame('2026-05-29T16:41:47.983Z', $result->createdAt);
        $this->assertSame('SUCCEEDED', $result->status);
        $this->assertSame(['https://example.com/video.mp4'], $result->output);
        $this->assertSame('https://example.com/video.mp4', $result->getOutputUrl());
    }

    #[Test]
    public function it_returns_failed_image_response_when_provider_rejects_request(): void
    {
        Http::fake([
            'https://runway.test/v1/text_to_image' => Http::response([
                'error' => 'Validation of body failed',
            ], 422),
        ]);

        $result = $this->client()->createImage('bad-url', 'prompt', '1360:768');

        $this->assertInstanceOf(VideoAIImage::class, $result);
        $this->assertSame('failed', $result->status);
        $this->assertSame(['error' => 'Validation of body failed'], $result->response);
        $this->assertTrue($result->isFailed());
    }

    #[Test]
    public function it_throws_exception_when_api_key_is_missing(): void
    {
        Config::set('videoai.drivers.runway.api_key', null);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Runway API key is not configured');

        new RunwayVideoAI(apiKey: '');
    }

    private function client(): RunwayVideoAI
    {
        return new RunwayVideoAI(
            apiKey: 'test-api-key',
            baseUrl: 'https://runway.test',
            apiVersion: '2024-11-06',
            imageModel: 'gen4_image',
            videoModel: 'gen4.5',
            referenceImageTag: 'base_image',
            defaultImageRatio: '1024:1024',
            defaultVideoRatio: '960:960',
            defaultDuration: 5
        );
    }
}
