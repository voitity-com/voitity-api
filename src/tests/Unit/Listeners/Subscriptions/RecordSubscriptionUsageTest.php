<?php

namespace Tests\Unit\Listeners\Subscriptions;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionUsageType;
use App\Events\Subscriptions\SubscriptionUsageRequested;
use App\Listeners\Subscriptions\RecordSubscriptionUsage;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\User;
use Tests\TestCase;

class RecordSubscriptionUsageTest extends TestCase
{
    public function test_it_records_subscription_usage_from_event(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Profile',
            'description' => 'Description',
            'genre' => 'neutral',
            'personality' => 'friendly',
            'active' => true,
        ]);
        $this->createActiveSubscriptionFor($user);

        $event = new SubscriptionUsageRequested(
            userId: $user->id,
            usageType: SubscriptionUsageType::VoiceTtsCharacters,
            amounts: ['tts_characters' => 150],
            profileId: $profile->id,
            sourceType: Profile::class,
            sourceId: (string) $profile->id,
            idempotencyKey: 'tts:test'
        );

        (new RecordSubscriptionUsage(new SubscriptionUsageRecorder))->handle($event);

        $limit = SubscriptionLimit::first();

        $this->assertSame(9850, $limit->tts_characters_remaining);
        $this->assertSame(992.5, $limit->credits_remaining);
        $this->assertDatabaseHas('subscription_uses', [
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'usage_type' => SubscriptionUsageType::VoiceTtsCharacters->value,
            'tts_characters_used' => 150,
            'credits_used' => 7.5,
            'idempotency_key' => 'tts:test',
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
