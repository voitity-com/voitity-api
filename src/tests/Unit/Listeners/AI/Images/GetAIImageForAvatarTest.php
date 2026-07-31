<?php

namespace Tests\Unit\Listeners\AI\Images;

use App\Classes\VideoAIService\AiImage as AiImageResult;
use App\Classes\VideoAIService\VideoAIArtifactStorage;
use App\Classes\VideoAIService\VideoAIService;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionUsageType;
use App\Events\AI\Images\AiImageForAvatarCreated;
use App\Events\AI\Images\AiImageForAvatarGenerated;
use App\Listeners\AI\Images\GetAIImageForAvatar;
use App\Models\AiImage;
use App\Models\AppNotification;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\SubscriptionUse;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
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
        Storage::fake('profiles');
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

        $listener = new GetAIImageForAvatar($service, new VideoAIArtifactStorage);
        $listener->handle(new AiImageForAvatarCreated($aiImage));

        $aiImage->refresh();

        $path = "images/{$aiImage->id}.png";

        $this->assertSame('succeeded', $aiImage->status);
        $this->assertSame(Storage::disk('profiles')->url($path), $aiImage->file);
        Storage::disk('profiles')->assertExists($path);
        Event::assertDispatched(AiImageForAvatarGenerated::class, function ($event) use ($aiImage) {
            return $event->aiImage->is($aiImage)
                && $event->sourceImageUrl === 'https://example.com/generated-image.png';
        });
    }

    #[Test]
    public function it_does_not_dispatch_generated_event_when_image_is_already_stored(): void
    {
        Event::fake([AiImageForAvatarGenerated::class]);

        $aiImage = $this->aiImage([
            'status' => 'succeeded',
            'file' => 'aiimages/1.png',
        ]);
        $service = Mockery::mock(VideoAIService::class);
        $service->shouldNotReceive('getImage');

        $listener = new GetAIImageForAvatar($service, new VideoAIArtifactStorage);
        $listener->handle(new AiImageForAvatarCreated($aiImage));

        Event::assertNotDispatched(AiImageForAvatarGenerated::class);
    }

    #[Test]
    public function it_marks_avatar_failed_persists_provider_failure_releases_usage_and_notifies_user(): void
    {
        Mail::fake();

        $aiImage = $this->aiImage();
        $profile = $aiImage->profile()->firstOrFail();
        $user = $aiImage->user()->firstOrFail();
        $subscription = $this->createActiveSubscriptionFor($user);
        $avatar = ProfileAvatar::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'aiimage_id' => $aiImage->id,
            'video_duration_seconds' => 2,
            'status' => ProfileAvatar::STATUS_PROCESSING,
        ]);
        SubscriptionUse::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'usage_type' => SubscriptionUsageType::AvatarGenerated,
            'source_type' => ProfileAvatar::class,
            'source_id' => (string) $avatar->id,
            'idempotency_key' => "avatar-generation:profile-avatar:{$avatar->id}",
            'avatar_images_used' => 1,
            'avatar_video_seconds_used' => 2,
            'metadata' => ['reservation' => 'avatar_generation'],
            'used_at' => now(),
        ]);

        $service = Mockery::mock(VideoAIService::class);
        $service->shouldReceive('getImage')
            ->once()
            ->with('image-source-id')
            ->andReturn(new AiImageResult(
                id: 'image-source-id',
                status: 'FAILED',
                response: [
                    'failure' => 'Image did not pass public figure content moderation.',
                    'failureCode' => 'SAFETY.OUTPUT.IMAGE',
                ]
            ));

        $listener = new GetAIImageForAvatar($service, new VideoAIArtifactStorage);
        $listener->handle(new AiImageForAvatarCreated($aiImage));

        $aiImage->refresh();
        $avatar->refresh();
        $limit = $subscription->limit()->firstOrFail();

        $this->assertSame('failed', $aiImage->status);
        $this->assertSame('SAFETY.OUTPUT.IMAGE', $aiImage->failure_code);
        $this->assertSame('Image did not pass public figure content moderation.', $aiImage->failure_reason);
        $this->assertSame(ProfileAvatar::STATUS_FAILED, $avatar->status);
        $this->assertSame('SAFETY.OUTPUT.IMAGE', $avatar->failure_code);
        $this->assertSame('Image did not pass public figure content moderation.', $avatar->failure_reason);
        $this->assertSame(1, (int) $limit->avatar_images_remaining);
        $this->assertSame(2, (int) $limit->avatar_video_seconds_remaining);
        $this->assertDatabaseHas('subscription_uses', [
            'idempotency_key' => "avatar-generation:profile-avatar:{$avatar->id}",
            'status' => SubscriptionUse::STATUS_RELEASED,
        ]);
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $user->id,
            'notification_key' => 'avatar_generation_failed',
            'category' => 'avatar',
            'action_url' => "/dashboard/profiles/{$profile->id}/avatar",
        ]);
        $notification = AppNotification::where('user_id', $user->id)
            ->where('notification_key', 'avatar_generation_failed')
            ->firstOrFail();
        $this->assertSame('Image did not pass public figure content moderation.', $notification->data['reason']);
    }

    private function aiImage(array $overrides = []): AiImage
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

        return AiImage::create(array_merge([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source_id' => 'image-source-id',
            'source' => 'runway',
            'status' => 'pending',
            'file' => null,
        ], $overrides));
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
