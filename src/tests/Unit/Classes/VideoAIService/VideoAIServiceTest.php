<?php

namespace Tests\Unit\Classes\VideoAIService;

use App\Classes\VideoAIService\VideoAIClient;
use App\Classes\VideoAIService\AiImage;
use App\Classes\VideoAIService\VideoAIService;
use App\Classes\VideoAIService\AiVideo;
use App\Events\AI\Images\AiImageCreated;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Event;
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
        $image = new AiImage(id: 'image-task-id');

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
        $video = new AiVideo(id: 'video-task-id');

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
        $image = new AiImage(id: 'image-task-id');
        $video = new AiVideo(id: 'video-task-id');

        $client->shouldReceive('getImage')->once()->with('image-task-id')->andReturn($image);
        $client->shouldReceive('getVideo')->once()->with('video-task-id')->andReturn($video);

        $service = new VideoAIService($client);

        $this->assertSame($image, $service->getImage('image-task-id'));
        $this->assertSame($video, $service->getVideo('video-task-id'));
    }

    #[Test]
    public function it_generates_image_record_and_dispatches_event(): void
    {
        Event::fake([AiImageCreated::class]);

        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Test profile',
            'description' => 'Test description',
            'genre' => 'test',
            'personality' => 'friendly',
            'active' => true,
        ]);
        $client = Mockery::mock(VideoAIClient::class);
        $client->shouldReceive('createImage')
            ->once()
            ->with('https://example.com/source.png', config('videoai.prompts.image'), '')
            ->andReturn(new AiImage(id: 'image-source-id', status: 'PENDING'));

        $service = new VideoAIService($client);
        $aiImage = $service->generateImage($user, 'https://example.com/source.png', $profile);

        $this->assertSame($user->id, $aiImage->user_id);
        $this->assertSame($profile->id, $aiImage->profile_id);
        $this->assertSame('image-source-id', $aiImage->source_id);
        $this->assertSame('pending', $aiImage->status);
        $this->assertNull($aiImage->file);

        Event::assertDispatched(AiImageCreated::class, fn ($event) => $event->aiImage->is($aiImage));
    }
}
