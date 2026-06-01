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
        $this->assertSame('pending', $aiVideo->status);
        $this->assertNull($aiVideo->file);

        Event::assertDispatched(AiVideoForAvatarCreated::class, function ($event) use ($aiVideo, $aiImage) {
            return $event->aiVideo->is($aiVideo)
                && $event->aiImage->is($aiImage);
        });
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
