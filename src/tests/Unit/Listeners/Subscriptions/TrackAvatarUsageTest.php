<?php

namespace Tests\Unit\Listeners\Subscriptions;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Events\AI\Images\AiImageForAvatarCreated;
use App\Events\AI\Videos\AiVideoForAvatarCreated;
use App\Listeners\Subscriptions\TrackAvatarImageUsage;
use App\Listeners\Subscriptions\TrackAvatarVideoUsage;
use App\Models\AiImage;
use App\Models\AiVideo;
use App\Models\Profile;
use App\Models\SubscriptionLimit;
use App\Models\User;
use Tests\TestCase;

class TrackAvatarUsageTest extends TestCase
{
    public function test_it_tracks_avatar_image_usage(): void
    {
        $user = User::factory()->create();
        $profile = $this->profileFor($user);
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
}
