<?php

namespace Tests\Unit\Classes\Repositories;

use App\Classes\Repositories\AvatarRepository;
use App\Classes\VideoAIService\VideoAIService;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionUsageType;
use App\Events\AI\Images\AiImageForAvatarCreated;
use App\Exceptions\Avatar\AvatarGenerationInProgressException;
use App\Models\AiImage as AiImageModel;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\SubscriptionUse;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class AvatarRepositoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_generates_avatar_image_and_dispatches_avatar_event(): void
    {
        config()->set('videoai.default', 'runway');
        config()->set('videoai.drivers.runway.default_duration', 5);

        Event::fake([AiImageForAvatarCreated::class]);
        Storage::fake('profiles');

        $user = User::factory()->create();
        $profile = $this->profileForUser($user);
        $this->createActiveSubscriptionFor($user);
        $service = Mockery::mock(VideoAIService::class);

        $service->shouldReceive('generateImage')
            ->once()
            ->with(
                Mockery::on(fn (User $owner) => $owner->is($user)),
                Mockery::on(fn (string $url) => str_starts_with($url, 'data:image/png;base64,')),
                Mockery::on(fn (Profile $receivedProfile) => $receivedProfile->is($profile))
            )
            ->andReturnUsing(function (User $owner, string $sourceImageUrl, Profile $receivedProfile): AiImageModel {
                return AiImageModel::create([
                    'user_id' => $owner->id,
                    'profile_id' => $receivedProfile->id,
                    'source_id' => 'image-source-id',
                    'source' => 'runway',
                    'status' => 'pending',
                    'file' => null,
                ]);
            });

        $repository = (new AvatarRepository)->setVideoAIService($service);
        $aiImage = $repository->generateAvatar($user, $profile, $this->validImageUpload());

        $this->assertSame($user->id, $aiImage->user_id);
        $this->assertSame($profile->id, $aiImage->profile_id);
        $this->assertCount(1, Storage::disk('profiles')->allFiles('images/sources'));
        $avatar = ProfileAvatar::where('aiimage_id', $aiImage->id)->firstOrFail();
        $this->assertDatabaseHas('profile_avatars', [
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'aiimage_id' => $aiImage->id,
            'status' => ProfileAvatar::STATUS_PROCESSING,
        ]);
        $this->assertDatabaseHas('subscription_uses', [
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'usage_type' => SubscriptionUsageType::AvatarImageCreated->value,
            'avatar_images_used' => 1,
            'idempotency_key' => "avatar-image:profile-avatar:{$avatar->id}",
        ]);
        $this->assertDatabaseHas('subscription_uses', [
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'usage_type' => SubscriptionUsageType::AvatarVideoCreated->value,
            'avatar_video_seconds_used' => 5,
            'idempotency_key' => "avatar-video:profile-avatar:{$avatar->id}",
        ]);
        $limit = $user->subscriptions()->where('active', true)->firstOrFail()->limit()->firstOrFail();
        $this->assertSame(0, (int) $limit->avatar_images_remaining);
        $this->assertSame(0, (int) $limit->avatar_video_seconds_remaining);
        Event::assertDispatched(AiImageForAvatarCreated::class, fn ($event) => $event->aiImage->is($aiImage));
    }

    #[Test]
    public function admin_generated_avatar_uses_profile_owner_as_artifact_owner(): void
    {
        Event::fake([AiImageForAvatarCreated::class]);
        Storage::fake('profiles');

        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'user']);
        $profile = $this->profileForUser($owner);
        $this->createActiveSubscriptionFor($owner);
        $service = Mockery::mock(VideoAIService::class);

        $service->shouldReceive('generateImage')
            ->once()
            ->with(
                Mockery::on(fn (User $receivedOwner) => $receivedOwner->is($owner)),
                Mockery::type('string'),
                Mockery::on(fn (Profile $receivedProfile) => $receivedProfile->is($profile))
            )
            ->andReturnUsing(function (User $receivedOwner, string $sourceImageUrl, Profile $receivedProfile): AiImageModel {
                return AiImageModel::create([
                    'user_id' => $receivedOwner->id,
                    'profile_id' => $receivedProfile->id,
                    'source_id' => 'image-source-id',
                    'source' => 'runway',
                    'status' => 'pending',
                    'file' => null,
                ]);
            });

        $repository = (new AvatarRepository)->setVideoAIService($service);
        $aiImage = $repository->generateAvatar($admin, $profile, $this->validImageUpload());

        $this->assertSame($owner->id, $aiImage->user_id);
        $this->assertDatabaseHas('profile_avatars', [
            'user_id' => $owner->id,
            'profile_id' => $profile->id,
            'aiimage_id' => $aiImage->id,
            'status' => ProfileAvatar::STATUS_PROCESSING,
        ]);
        Event::assertDispatched(AiImageForAvatarCreated::class, fn ($event) => $event->aiImage->is($aiImage));
    }

    #[Test]
    public function it_releases_reserved_avatar_usage_when_image_generation_fails(): void
    {
        Event::fake([AiImageForAvatarCreated::class]);
        Storage::fake('profiles');

        $user = User::factory()->create();
        $profile = $this->profileForUser($user);
        $subscription = $this->createActiveSubscriptionFor($user);
        $service = Mockery::mock(VideoAIService::class);

        $service->shouldReceive('generateImage')
            ->once()
            ->andThrow(new RuntimeException('Provider image generation failed.'));

        $repository = (new AvatarRepository)->setVideoAIService($service);

        try {
            $repository->generateAvatar($user, $profile, $this->validImageUpload());
            $this->fail('Avatar generation should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Provider image generation failed.', $exception->getMessage());
        }

        $avatar = ProfileAvatar::where('profile_id', $profile->id)->firstOrFail();
        $limit = $subscription->limit()->firstOrFail();

        $this->assertSame(ProfileAvatar::STATUS_FAILED, $avatar->status);
        $this->assertSame(1, (int) $limit->avatar_images_remaining);
        $this->assertSame(5, (int) $limit->avatar_video_seconds_remaining);
        $this->assertDatabaseHas('subscription_uses', [
            'idempotency_key' => "avatar-image:profile-avatar:{$avatar->id}",
            'status' => SubscriptionUse::STATUS_RELEASED,
        ]);
        $this->assertDatabaseHas('subscription_uses', [
            'idempotency_key' => "avatar-video:profile-avatar:{$avatar->id}",
            'status' => SubscriptionUse::STATUS_RELEASED,
        ]);
        Event::assertNotDispatched(AiImageForAvatarCreated::class);
    }

    #[Test]
    public function it_rejects_generation_when_profile_has_processing_avatar(): void
    {
        Event::fake([AiImageForAvatarCreated::class]);
        Storage::fake('profiles');

        $user = User::factory()->create();
        $profile = $this->profileForUser($user);

        ProfileAvatar::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => ProfileAvatar::STATUS_PROCESSING,
        ]);

        $service = Mockery::mock(VideoAIService::class);
        $service->shouldNotReceive('generateImage');

        $repository = (new AvatarRepository)->setVideoAIService($service);

        $this->expectException(AvatarGenerationInProgressException::class);

        $repository->generateAvatar($user, $profile, $this->validImageUpload());
    }

    private function profileForUser(User $user): Profile
    {
        return Profile::create([
            'user_id' => $user->id,
            'name' => 'Test profile',
            'description' => 'Test description',
            'genre' => 'test',
            'personality' => 'friendly',
            'active' => true,
        ]);
    }

    private function validImageUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'avatar_');
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='
        ));

        return new UploadedFile($path, 'avatar.png', 'image/png', null, true);
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
            'avatar_images_remaining' => 1,
            'avatar_video_seconds_remaining' => 5,
            'voice_clones_remaining' => 1,
            'tts_characters_remaining' => 10000,
            'chat_messages_remaining' => 1000,
            'credits_remaining' => 1000,
        ]);

        return $subscription;
    }
}
