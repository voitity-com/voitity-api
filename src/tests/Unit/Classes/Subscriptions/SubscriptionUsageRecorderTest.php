<?php

namespace Tests\Unit\Classes\Subscriptions;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionUsageType;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\SubscriptionUse;
use App\Models\User;
use Tests\TestCase;

class SubscriptionUsageRecorderTest extends TestCase
{
    public function test_it_creates_starter_subscription_limits_and_usage(): void
    {
        $user = User::factory()->create();
        $profile = $this->profileFor($user);

        $use = (new SubscriptionUsageRecorder)->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::ProfileCreated,
            amounts: ['profiles' => 1],
            idempotencyKey: "profile-created:{$profile->id}",
            profileId: $profile->id,
            sourceType: Profile::class,
            sourceId: (string) $profile->id
        );

        $subscription = Subscription::first();
        $limit = SubscriptionLimit::first();

        $this->assertNotNull($subscription);
        $this->assertSame($user->id, $subscription->user_id);
        $this->assertSame(SubscriptionPlan::Starter, $subscription->plan);
        $this->assertSame(SubscriptionStatus::First, $subscription->status);
        $this->assertTrue($subscription->active);

        $this->assertNotNull($limit);
        $this->assertSame($subscription->id, $limit->subscription_id);
        $this->assertSame(0, $limit->profiles_remaining);
        $this->assertSame(1, $limit->avatar_images_remaining);
        $this->assertSame(5, $limit->avatar_video_seconds_remaining);
        $this->assertSame(1, $limit->voice_clones_remaining);
        $this->assertSame(10000, $limit->tts_characters_remaining);
        $this->assertSame(1000, $limit->chat_messages_remaining);

        $this->assertSame($subscription->id, $use->subscription_id);
        $this->assertSame($profile->id, $use->profile_id);
        $this->assertSame(1, $use->profiles_used);
        $this->assertSame(SubscriptionUsageType::ProfileCreated, $use->usage_type);
    }

    public function test_it_is_idempotent_per_usage_key(): void
    {
        $user = User::factory()->create();
        $profile = $this->profileFor($user);
        $recorder = new SubscriptionUsageRecorder;

        $firstUse = $recorder->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::AvatarImageCreated,
            amounts: ['avatar_images' => 1],
            idempotencyKey: 'avatar-image:10',
            profileId: $profile->id
        );

        $secondUse = $recorder->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::AvatarImageCreated,
            amounts: ['avatar_images' => 1],
            idempotencyKey: 'avatar-image:10',
            profileId: $profile->id
        );

        $this->assertSame($firstUse->id, $secondUse->id);
        $this->assertSame(1, SubscriptionUse::count());
        $this->assertSame(0, SubscriptionLimit::first()->avatar_images_remaining);
    }

    public function test_it_renews_expired_subscription_and_resets_limits(): void
    {
        $user = User::factory()->create();
        $profile = $this->profileFor($user);
        $expiredSubscription = Subscription::create([
            'user_id' => $user->id,
            'plan' => SubscriptionPlan::Starter,
            'started_at' => now()->subMonths(2),
            'renews_at' => now()->subMonth(),
            'status' => SubscriptionStatus::First,
            'active' => true,
        ]);

        SubscriptionLimit::create([
            'subscription_id' => $expiredSubscription->id,
            'user_id' => $user->id,
            'period_started_at' => $expiredSubscription->started_at,
            'period_renews_at' => $expiredSubscription->renews_at,
            'profiles_remaining' => 0,
            'avatar_images_remaining' => 0,
            'avatar_video_seconds_remaining' => 0,
            'voice_clones_remaining' => 0,
            'tts_characters_remaining' => 0,
            'chat_messages_remaining' => 0,
        ]);

        (new SubscriptionUsageRecorder)->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::ChatOpenAiCall,
            amounts: ['chat_messages' => 1],
            idempotencyKey: 'chat-openai:message:99',
            profileId: $profile->id
        );

        $expiredSubscription->refresh();
        $renewedSubscription = Subscription::where('active', true)->first();
        $renewedLimit = $renewedSubscription->limit;

        $this->assertFalse($expiredSubscription->active);
        $this->assertSame(SubscriptionStatus::Expired, $expiredSubscription->status);
        $this->assertSame(SubscriptionStatus::Renewed, $renewedSubscription->status);
        $this->assertSame(999, $renewedLimit->chat_messages_remaining);
        $this->assertSame(1, $renewedLimit->profiles_remaining);
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
