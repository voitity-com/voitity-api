<?php

namespace Tests\Unit\Classes\VideoAIService;

use App\Classes\VideoAIService\VideoAIClient;
use App\Classes\VideoAIService\VideoAIImage;
use App\Classes\VideoAIService\VideoAIService;
use App\Classes\VideoAIService\VideoAIVideo;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VideoAIServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_delegates_create_image_to_client(): void
    {
        $client = Mockery::mock(VideoAIClient::class);
        $image = new VideoAIImage(id: 'image-task-id');

        $client->shouldReceive('createImage')
            ->once()
            ->with('https://example.com/source.png', 'prompt', '1360:768')
            ->andReturn($image);

        $service = new VideoAIService($client);

        $this->assertSame($image, $service->createImage('https://example.com/source.png', 'prompt', '1360:768'));
    }

    #[Test]
    public function it_delegates_create_video_to_client(): void
    {
        $client = Mockery::mock(VideoAIClient::class);
        $video = new VideoAIVideo(id: 'video-task-id');

        $client->shouldReceive('createVideo')
            ->once()
            ->with('https://example.com/source.png', 'prompt', '1280:720', 5)
            ->andReturn($video);

        $service = new VideoAIService($client);

        $this->assertSame($video, $service->createVideo('https://example.com/source.png', 'prompt', '1280:720'));
    }

    #[Test]
    public function it_delegates_get_methods_to_client(): void
    {
        $client = Mockery::mock(VideoAIClient::class);
        $image = new VideoAIImage(id: 'image-task-id');
        $video = new VideoAIVideo(id: 'video-task-id');

        $client->shouldReceive('getImage')->once()->with('image-task-id')->andReturn($image);
        $client->shouldReceive('getVideo')->once()->with('video-task-id')->andReturn($video);

        $service = new VideoAIService($client);

        $this->assertSame($image, $service->getImage('image-task-id'));
        $this->assertSame($video, $service->getVideo('video-task-id'));
    }
}
