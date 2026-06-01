<?php

namespace Tests\Unit\Listeners\AI\Videos;

use App\Classes\VideoAIService\AiVideo as AiVideoResult;
use App\Classes\VideoAIService\VideoAIArtifactStorage;
use App\Classes\VideoAIService\VideoAIService;
use App\Events\AI\Videos\AiVideoForAvatarCreated;
use App\Listeners\AI\Videos\GetAIVideoForAvatar;
use App\Models\AiImage;
use App\Models\AiVideo;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GetAIVideoForAvatarTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_stores_generated_video_updates_record_and_creates_avatar(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://example.com/generated-video.mp4' => Http::response('video-bytes', 200, [
                'Content-Type' => 'video/mp4',
            ]),
        ]);

        [$aiImage, $aiVideo] = $this->aiImageAndVideo();
        $service = Mockery::mock(VideoAIService::class);
        $service->shouldReceive('getVideo')
            ->once()
            ->with('video-source-id')
            ->andReturn(new AiVideoResult(
                id: 'video-source-id',
                status: 'SUCCEEDED',
                output: ['https://example.com/generated-video.mp4']
            ));

        $listener = new GetAIVideoForAvatar($service, new VideoAIArtifactStorage());
        $listener->handle(new AiVideoForAvatarCreated($aiVideo, $aiImage));

        $aiVideo->refresh();
        $avatar = ProfileAvatar::where('profile_id', $aiVideo->profile_id)->first();

        $this->assertSame('succeeded', $aiVideo->status);
        $this->assertSame("aivideos/{$aiVideo->id}.mp4", $aiVideo->file);
        Storage::disk('public')->assertExists($aiVideo->file);
        $this->assertNotNull($avatar);
        $this->assertSame($aiImage->id, $avatar->aiimage_id);
        $this->assertSame($aiVideo->id, $avatar->ai_video_id);
        $this->assertSame($aiVideo->file, $avatar->file);
        $this->assertSame('active', $avatar->status);
    }

    #[Test]
    public function it_updates_only_video_and_file_when_avatar_exists(): void
    {
        Storage::fake('public');
        Http::fake([
            'https://example.com/generated-video.mp4' => Http::response('video-bytes', 200, [
                'Content-Type' => 'video/mp4',
            ]),
        ]);

        [$aiImage, $aiVideo] = $this->aiImageAndVideo();
        $existingAiImage = AiImage::create([
            'user_id' => $aiImage->user_id,
            'profile_id' => $aiImage->profile_id,
            'source_id' => 'old-image-source-id',
            'source' => 'runway',
            'status' => 'succeeded',
            'file' => 'aiimages/old.png',
        ]);
        $avatar = ProfileAvatar::create([
            'user_id' => $aiVideo->user_id,
            'profile_id' => $aiVideo->profile_id,
            'aiimage_id' => $existingAiImage->id,
            'ai_video_id' => null,
            'file' => 'old-file.mp4',
            'status' => 'active',
        ]);

        $service = Mockery::mock(VideoAIService::class);
        $service->shouldReceive('getVideo')
            ->once()
            ->with('video-source-id')
            ->andReturn(new AiVideoResult(
                id: 'video-source-id',
                status: 'SUCCEEDED',
                output: ['https://example.com/generated-video.mp4']
            ));

        $listener = new GetAIVideoForAvatar($service, new VideoAIArtifactStorage());
        $listener->handle(new AiVideoForAvatarCreated($aiVideo, $aiImage));

        $avatar->refresh();
        $aiVideo->refresh();

        $this->assertSame($existingAiImage->id, $avatar->aiimage_id);
        $this->assertSame($aiVideo->id, $avatar->ai_video_id);
        $this->assertSame($aiVideo->file, $avatar->file);
    }

    private function aiImageAndVideo(): array
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
        $aiImage = AiImage::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source_id' => 'image-source-id',
            'source' => 'runway',
            'status' => 'succeeded',
            'file' => 'aiimages/1.png',
        ]);
        $aiVideo = AiVideo::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source_id' => 'video-source-id',
            'source' => 'runway',
            'status' => 'pending',
            'file' => null,
        ]);

        return [$aiImage, $aiVideo];
    }
}
