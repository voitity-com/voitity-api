<?php

namespace Tests\Unit\Listeners\AI\Videos;

use App\Classes\VideoAIService\AiVideo as AiVideoResult;
use App\Classes\VideoAIService\VideoAIService;
use App\Events\AI\Images\AiImageForAvatarGenerated;
use App\Events\AI\Videos\AiVideoForAvatarCreated;
use App\Listeners\AI\Videos\CreateAiVideoForAvatar;
use App\Models\AiImage;
use App\Models\AiVideo;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreateAiVideoForAvatarTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_creates_ai_video_record_and_dispatches_event(): void
    {
        Event::fake([AiVideoForAvatarCreated::class]);

        $aiImage = $this->aiImage();
        $service = Mockery::mock(VideoAIService::class);
        $service->shouldReceive('createVideo')
            ->once()
            ->with('https://example.com/generated-image.png', config('videoai.prompts.video'))
            ->andReturn(new AiVideoResult(id: 'video-source-id', status: 'PENDING'));

        $listener = new CreateAiVideoForAvatar($service);
        $listener->handle(new AiImageForAvatarGenerated($aiImage, 'https://example.com/generated-image.png'));

        $aiVideo = AiVideo::where('source_id', 'video-source-id')->first();

        $this->assertNotNull($aiVideo);
        $this->assertSame($aiImage->user_id, $aiVideo->user_id);
        $this->assertSame($aiImage->profile_id, $aiVideo->profile_id);
        $this->assertSame($aiImage->id, $aiVideo->aiimage_id);
        $this->assertSame('pending', $aiVideo->status);
        $this->assertNull($aiVideo->file);

        Event::assertDispatched(AiVideoForAvatarCreated::class, function ($event) use ($aiVideo, $aiImage) {
            return $event->aiVideo->is($aiVideo)
                && $event->aiImage->is($aiImage);
        });
    }

    #[Test]
    public function it_does_not_create_duplicate_video_when_aiimage_already_has_one(): void
    {
        Event::fake([AiVideoForAvatarCreated::class]);

        $aiImage = $this->aiImage();
        $existingAiVideo = AiVideo::create([
            'user_id' => $aiImage->user_id,
            'profile_id' => $aiImage->profile_id,
            'aiimage_id' => $aiImage->id,
            'source_id' => 'existing-video-source-id',
            'source' => 'runway',
            'status' => 'running',
            'file' => null,
        ]);
        $service = Mockery::mock(VideoAIService::class);
        $service->shouldNotReceive('createVideo');

        $listener = new CreateAiVideoForAvatar($service);
        $listener->handle(new AiImageForAvatarGenerated($aiImage, 'https://example.com/generated-image.png'));

        $this->assertSame(1, AiVideo::where('aiimage_id', $aiImage->id)->count());
        $this->assertTrue($existingAiVideo->fresh()->is(AiVideo::where('aiimage_id', $aiImage->id)->first()));
        Event::assertNotDispatched(AiVideoForAvatarCreated::class);
    }

    private function aiImage(): AiImage
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Test profile',
            'description' => 'Test description',
            'genre' => 'test',
            'personality' => 'friendly',
            'active' => true,
        ]);

        return AiImage::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source_id' => 'image-source-id',
            'source' => 'runway',
            'status' => 'succeeded',
            'file' => 'aiimages/1.png',
        ]);
    }
}
