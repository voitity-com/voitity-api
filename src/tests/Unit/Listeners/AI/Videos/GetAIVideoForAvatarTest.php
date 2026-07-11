<?php

namespace Tests\Unit\Listeners\AI\Videos;

use App\Classes\VideoAIService\AiVideo as AiVideoResult;
use App\Classes\VideoAIService\VideoAIArtifactStorage;
use App\Classes\VideoAIService\VideoAIService;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionUsageType;
use App\Events\AI\Videos\AiVideoForAvatarCreated;
use App\Listeners\AI\Videos\GetAIVideoForAvatar;
use App\Models\AiImage;
use App\Models\AiVideo;
use App\Models\AppNotification;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\SubscriptionUse;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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
        Storage::fake('profiles');
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

        $listener = new GetAIVideoForAvatar($service, new VideoAIArtifactStorage);
        $listener->handle(new AiVideoForAvatarCreated($aiVideo, $aiImage));

        $aiVideo->refresh();
        $avatar = ProfileAvatar::where('profile_id', $aiVideo->profile_id)->first();

        $path = "videos/{$aiVideo->id}.mp4";

        $this->assertSame('succeeded', $aiVideo->status);
        $this->assertSame(Storage::disk('profiles')->url($path), $aiVideo->file);
        Storage::disk('profiles')->assertExists($path);
        $this->assertNotNull($avatar);
        $this->assertSame($aiImage->id, $avatar->aiimage_id);
        $this->assertSame($aiVideo->id, $avatar->ai_video_id);
        $this->assertSame($aiVideo->file, $avatar->file);
        $this->assertSame('active', $avatar->status);
    }

    #[Test]
    public function it_activates_processing_avatar_and_inactivates_previous_avatar(): void
    {
        Storage::fake('profiles');
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
            'status' => ProfileAvatar::STATUS_ACTIVE,
        ]);
        $processingAvatar = ProfileAvatar::create([
            'user_id' => $aiVideo->user_id,
            'profile_id' => $aiVideo->profile_id,
            'aiimage_id' => $aiImage->id,
            'ai_video_id' => null,
            'file' => null,
            'status' => ProfileAvatar::STATUS_PROCESSING,
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

        $listener = new GetAIVideoForAvatar($service, new VideoAIArtifactStorage);
        $listener->handle(new AiVideoForAvatarCreated($aiVideo, $aiImage));

        $avatar->refresh();
        $processingAvatar->refresh();
        $aiVideo->refresh();

        $this->assertSame($existingAiImage->id, $avatar->aiimage_id);
        $this->assertSame(ProfileAvatar::STATUS_INACTIVE, $avatar->status);
        $this->assertSame($aiImage->id, $processingAvatar->aiimage_id);
        $this->assertSame($aiVideo->id, $processingAvatar->ai_video_id);
        $this->assertSame($aiVideo->file, $processingAvatar->file);
        $this->assertSame(ProfileAvatar::STATUS_ACTIVE, $processingAvatar->status);
    }

    #[Test]
    public function it_marks_avatar_failed_persists_provider_failure_releases_usage_and_notifies_user_when_video_fails(): void
    {
        Mail::fake();

        [$aiImage, $aiVideo] = $this->aiImageAndVideo();
        $user = $aiVideo->user()->firstOrFail();
        $profile = $aiVideo->profile()->firstOrFail();
        $subscription = $this->createActiveSubscriptionFor($user);
        $avatar = ProfileAvatar::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'aiimage_id' => $aiImage->id,
            'status' => ProfileAvatar::STATUS_PROCESSING,
        ]);
        SubscriptionUse::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'usage_type' => SubscriptionUsageType::AvatarImageCreated,
            'source_type' => ProfileAvatar::class,
            'source_id' => (string) $avatar->id,
            'idempotency_key' => "avatar-image:profile-avatar:{$avatar->id}",
            'avatar_images_used' => 1,
            'metadata' => ['reservation' => 'avatar_generation'],
            'used_at' => now(),
        ]);
        SubscriptionUse::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'usage_type' => SubscriptionUsageType::AvatarVideoCreated,
            'source_type' => ProfileAvatar::class,
            'source_id' => (string) $avatar->id,
            'idempotency_key' => "avatar-video:profile-avatar:{$avatar->id}",
            'avatar_video_seconds_used' => 5,
            'metadata' => ['reservation' => 'avatar_generation'],
            'used_at' => now(),
        ]);

        $service = Mockery::mock(VideoAIService::class);
        $service->shouldReceive('getVideo')
            ->once()
            ->with('video-source-id')
            ->andReturn(new AiVideoResult(
                id: 'video-source-id',
                status: 'FAILED',
                response: [
                    'failure' => 'Video generation failed moderation.',
                    'failureCode' => 'SAFETY.OUTPUT.VIDEO',
                ]
            ));

        $listener = new GetAIVideoForAvatar($service, new VideoAIArtifactStorage);
        $listener->handle(new AiVideoForAvatarCreated($aiVideo, $aiImage));

        $aiVideo->refresh();
        $avatar->refresh();
        $limit = $subscription->limit()->firstOrFail();

        $this->assertSame('failed', $aiVideo->status);
        $this->assertSame('SAFETY.OUTPUT.VIDEO', $aiVideo->failure_code);
        $this->assertSame('Video generation failed moderation.', $aiVideo->failure_reason);
        $this->assertSame(ProfileAvatar::STATUS_FAILED, $avatar->status);
        $this->assertSame('SAFETY.OUTPUT.VIDEO', $avatar->failure_code);
        $this->assertSame('Video generation failed moderation.', $avatar->failure_reason);
        $this->assertSame(1, (int) $limit->avatar_images_remaining);
        $this->assertSame(5, (int) $limit->avatar_video_seconds_remaining);
        $this->assertDatabaseMissing('subscription_uses', [
            'idempotency_key' => "avatar-image:profile-avatar:{$avatar->id}",
        ]);
        $this->assertDatabaseMissing('subscription_uses', [
            'idempotency_key' => "avatar-video:profile-avatar:{$avatar->id}",
        ]);
        $notification = AppNotification::where('user_id', $user->id)
            ->where('notification_key', 'avatar_generation_failed')
            ->firstOrFail();
        $this->assertSame('Video generation failed moderation.', $notification->data['reason']);
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
            'aiimage_id' => $aiImage->id,
            'source_id' => 'video-source-id',
            'source' => 'runway',
            'status' => 'pending',
            'file' => null,
        ]);

        return [$aiImage, $aiVideo];
    }

    private function createActiveSubscriptionFor(User $user): Subscription
    {
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan' => SubscriptionPlan::Starter,
            'started_at' => now()->subDay(),
            'renews_at' => now()->addMonth(),
            'status' => SubscriptionStatus::First,
            'active' => true,
        ]);

        SubscriptionLimit::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'period_started_at' => $subscription->started_at,
            'period_renews_at' => $subscription->renews_at,
            'profiles_remaining' => 1,
            'avatar_images_remaining' => 0,
            'avatar_video_seconds_remaining' => 0,
            'voice_clones_remaining' => 1,
            'tts_characters_remaining' => 10000,
            'chat_messages_remaining' => 1000,
            'credits_remaining' => 1000,
        ]);

        return $subscription;
    }
}
