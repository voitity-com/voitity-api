<?php

namespace Tests\Unit\Listeners\AI\Images;

use App\Classes\VideoAIService\AiImage as AiImageResult;
use App\Classes\VideoAIService\VideoAIArtifactStorage;
use App\Classes\VideoAIService\VideoAIService;
use App\Events\AI\Images\AiImageForAvatarCreated;
use App\Events\AI\Images\AiImageForAvatarGenerated;
use App\Listeners\AI\Images\GetAIImageForAvatar;
use App\Models\AiImage;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GetAIImageForAvatarTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_stores_generated_image_updates_record_and_dispatches_generated_event(): void
    {
        Event::fake([AiImageForAvatarGenerated::class]);
        Storage::fake('public');
        Http::fake([
            'https://example.com/generated-image.png' => Http::response('image-bytes', 200, [
                'Content-Type' => 'image/png',
            ]),
        ]);

        $aiImage = $this->aiImage();
        $service = Mockery::mock(VideoAIService::class);
        $service->shouldReceive('getImage')
            ->once()
            ->with('image-source-id')
            ->andReturn(new AiImageResult(
                id: 'image-source-id',
                status: 'SUCCEEDED',
                output: ['https://example.com/generated-image.png']
            ));

        $listener = new GetAIImageForAvatar($service, new VideoAIArtifactStorage());
        $listener->handle(new AiImageForAvatarCreated($aiImage));

        $aiImage->refresh();

        $this->assertSame('succeeded', $aiImage->status);
        $this->assertSame("aiimages/{$aiImage->id}.png", $aiImage->file);
        Storage::disk('public')->assertExists($aiImage->file);
        Event::assertDispatched(AiImageForAvatarGenerated::class, function ($event) use ($aiImage) {
            return $event->aiImage->is($aiImage)
                && $event->sourceImageUrl === 'https://example.com/generated-image.png';
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
            'status' => 'pending',
            'file' => null,
        ]);
    }
}
