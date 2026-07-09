<?php

namespace Tests\Unit\Listeners\Subscriptions;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionUsageType;
use App\Events\AI\Images\AiImageForAvatarCreated;
use App\Events\AI\Videos\AiVideoForAvatarCreated;
use App\Listeners\Subscriptions\TrackAvatarImageUsage;
use App\Listeners\Subscriptions\TrackAvatarVideoUsage;
use App\Models\AiImage;
use App\Models\AiVideo;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\SubscriptionUse;
use App\Models\User;
use Tests\TestCase;

class TrackAvatarUsageTest extends TestCase
{
    public function test_it_tracks_avatar_image_usage(): void
    {
        $user = User::factory()->create();
        $profile = $this->profileFor($user);
        $this->createActiveSubscriptionFor($user);
        $aiImage = AiImage::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source_id' => 'image-source-id',
            'source' => 'runway',
            'status' => 'pending',
        ]);

        (new TrackAvatarImageUsage(new SubscriptionUsageRecorder))->handle(
            new AiImageForAvatarCreated($aiImage)
        );

        $this->assertSame(0, SubscriptionLimit::first()->avatar_images_remaining);
        $this->assertDatabaseHas('subscription_uses', [
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'avatar_images_used' => 1,
            'idempotency_key' => "avatar-image:{$aiImage->id}",
        ]);
    }

    public function test_it_tracks_avatar_video_seconds_usage(): void
    {
        config()->set('videoai.drivers.runway.default_duration', 5);

        $user = User::factory()->create();
        $profile = $this->profileFor($user);
        $this->createActiveSubscriptionFor($user);
        $aiVideo = AiVideo::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source_id' => 'video-source-id',
            'source' => 'runway',
            'status' => 'pending',
        ]);

        (new TrackAvatarVideoUsage(new SubscriptionUsageRecorder))->handle(
            new AiVideoForAvatarCreated($aiVideo)
        );

        $this->assertSame(0, SubscriptionLimit::first()->avatar_video_seconds_remaining);
        $this->assertDatabaseHas('subscription_uses', [
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'avatar_video_seconds_used' => 5,
            'idempotency_key' => "avatar-video:{$aiVideo->id}",
        ]);
    }

    public function test_it_reuses_profile_avatar_reservations_when_avatar_events_are_processed(): void
    {
        config()->set('videoai.drivers.runway.default_duration', 5);

        $user = User::factory()->create();
        $profile = $this->profileFor($user);
        $subscription = $this->createActiveSubscriptionFor($user);
        $aiImage = AiImage::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source_id' => 'image-source-id',
            'source' => 'runway',
            'status' => 'pending',
        ]);
        $aiVideo = AiVideo::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'aiimage_id' => $aiImage->id,
            'source_id' => 'video-source-id',
            'source' => 'runway',
            'status' => 'pending',
        ]);
        $avatar = ProfileAvatar::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'aiimage_id' => $aiImage->id,
            'ai_video_id' => $aiVideo->id,
            'status' => ProfileAvatar::STATUS_PROCESSING,
        ]);
        $recorder = new SubscriptionUsageRecorder;

        $recorder->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::AvatarImageCreated,
            amounts: ['avatar_images' => 1],
            idempotencyKey: "avatar-image:profile-avatar:{$avatar->id}",
            profileId: $profile->id,
            sourceType: ProfileAvatar::class,
            sourceId: (string) $avatar->id,
        );
        $recorder->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::AvatarVideoCreated,
            amounts: ['avatar_video_seconds' => 5],
            idempotencyKey: "avatar-video:profile-avatar:{$avatar->id}",
            profileId: $profile->id,
            sourceType: ProfileAvatar::class,
            sourceId: (string) $avatar->id,
        );

        (new TrackAvatarImageUsage($recorder))->handle(new AiImageForAvatarCreated($aiImage));
        (new TrackAvatarVideoUsage($recorder))->handle(new AiVideoForAvatarCreated($aiVideo, $aiImage));

        $this->assertSame(1, SubscriptionUse::where('idempotency_key', "avatar-image:profile-avatar:{$avatar->id}")->count());
        $this->assertSame(1, SubscriptionUse::where('idempotency_key', "avatar-video:profile-avatar:{$avatar->id}")->count());
        $this->assertSame(2, SubscriptionUse::where('user_id', $user->id)->count());

        $limit = $subscription->limit()->firstOrFail();
        $this->assertSame(0, (int) $limit->avatar_images_remaining);
        $this->assertSame(0, (int) $limit->avatar_video_seconds_remaining);
    }

    private function profileFor(User $user): Profile
    {
        return Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Description',
            'genre' => 'neutral',
            'personality' => 'friendly',
            'active' => true,
        ]);
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
