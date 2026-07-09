<?php

namespace Tests\Unit\Classes\Subscriptions;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionUsageType;
use App\Exceptions\Subscriptions\SubscriptionEntitlementException;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\SubscriptionUse;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SubscriptionUsageRecorderTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_records_usage_against_active_starter_subscription(): void
    {
        $user = User::factory()->create();
        $profile = $this->profileFor($user);
        $subscription = $this->createActiveSubscriptionFor($user);

        $use = (new SubscriptionUsageRecorder)->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::ProfileCreated,
            amounts: ['profiles' => 1],
            idempotencyKey: "profile-created:{$profile->id}",
            profileId: $profile->id,
            sourceType: Profile::class,
            sourceId: (string) $profile->id
        );

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
        $this->assertSame(1000.0, $limit->credits_remaining);

        $this->assertSame($subscription->id, $use->subscription_id);
        $this->assertSame($profile->id, $use->profile_id);
        $this->assertSame(1, $use->profiles_used);
        $this->assertSame(0.0, $use->credits_used);
        $this->assertSame(SubscriptionUsageType::ProfileCreated, $use->usage_type);
    }

    public function test_it_is_idempotent_per_usage_key(): void
    {
        $user = User::factory()->create();
        $profile = $this->profileFor($user);
        $this->createActiveSubscriptionFor($user);
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
        $this->assertSame(1000.0, SubscriptionLimit::first()->credits_remaining);
    }

    public function test_it_reduces_credits_for_chat_messages_and_tts_characters(): void
    {
        $user = User::factory()->create();
        $profile = $this->profileFor($user);
        $this->createActiveSubscriptionFor($user);
        $recorder = new SubscriptionUsageRecorder;

        $chatUse = $recorder->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::ChatOpenAiCall,
            amounts: ['chat_messages' => 1],
            idempotencyKey: 'chat-openai:message:1',
            profileId: $profile->id
        );

        $ttsUse = $recorder->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::VoiceTtsCharacters,
            amounts: ['tts_characters' => 150],
            idempotencyKey: 'tts:voice:1',
            profileId: $profile->id
        );

        $limit = SubscriptionLimit::first();

        $this->assertSame(0.5, $chatUse->credits_used);
        $this->assertSame(7.5, $ttsUse->credits_used);
        $this->assertSame(999, $limit->chat_messages_remaining);
        $this->assertSame(9850, $limit->tts_characters_remaining);
        $this->assertSame(992.0, $limit->credits_remaining);
    }

    public function test_it_releases_recorded_usage_and_restores_current_period_limits(): void
    {
        $user = User::factory()->create();
        $profile = $this->profileFor($user);
        $this->createActiveSubscriptionFor($user);
        $recorder = new SubscriptionUsageRecorder;

        $use = $recorder->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::VoiceTtsCharacters,
            amounts: ['tts_characters' => 20],
            idempotencyKey: 'tts:voice:release-test',
            profileId: $profile->id
        );

        $limit = SubscriptionLimit::firstOrFail();

        $this->assertSame(9980, $limit->tts_characters_remaining);
        $this->assertSame(999.0, $limit->credits_remaining);
        $this->assertDatabaseHas('subscription_uses', ['id' => $use->id]);

        $this->assertTrue($recorder->release('tts:voice:release-test'));

        $limit->refresh();

        $this->assertSame(10000, $limit->tts_characters_remaining);
        $this->assertSame(1000.0, $limit->credits_remaining);
        $this->assertDatabaseMissing('subscription_uses', ['id' => $use->id]);
        $this->assertFalse($recorder->release('tts:voice:release-test'));
        $this->assertSame(10000, $limit->fresh()->tts_characters_remaining);
        $this->assertSame(1000.0, $limit->fresh()->credits_remaining);
    }

    public function test_it_does_not_renew_expired_paid_subscription(): void
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
            'credits_remaining' => 0,
        ]);

        $this->expectException(SubscriptionEntitlementException::class);
        $this->expectExceptionMessage('Active subscription has expired.');

        try {
            (new SubscriptionUsageRecorder)->record(
                userId: $user->id,
                usageType: SubscriptionUsageType::ChatOpenAiCall,
                amounts: ['chat_messages' => 1],
                idempotencyKey: 'chat-openai:message:99',
                profileId: $profile->id
            );
        } finally {
            $expiredSubscription->refresh();

            $this->assertFalse($expiredSubscription->active);
            $this->assertSame(SubscriptionStatus::Expired, $expiredSubscription->status);
            $this->assertSame(1, Subscription::where('user_id', $user->id)->count());
        }
    }

    public function test_it_does_not_create_subscription_when_user_has_no_active_subscription(): void
    {
        $user = User::factory()->create();
        $profile = $this->profileFor($user);

        $this->expectException(SubscriptionEntitlementException::class);
        $this->expectExceptionMessage('Active subscription not found.');

        (new SubscriptionUsageRecorder)->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::ChatOpenAiCall,
            amounts: ['chat_messages' => 1],
            idempotencyKey: 'chat-openai:message:100',
            profileId: $profile->id
        );
    }

    public function test_it_resets_annual_monthly_limits_before_recording_usage(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-16 10:00:00'));

        $user = User::factory()->create();
        $profile = $this->profileFor($user);
        $subscription = $this->createActiveSubscriptionFor(
            $user,
            plan: SubscriptionPlan::StarterAnnual,
            renewsAt: Carbon::parse('2027-01-15 10:00:00'),
            periodStartedAt: Carbon::parse('2026-01-15 10:00:00'),
            periodRenewsAt: Carbon::parse('2026-02-15 10:00:00'),
            profilesRemaining: 0
        );

        (new SubscriptionUsageRecorder)->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::ProfileCreated,
            amounts: ['profiles' => 1],
            idempotencyKey: 'profile-created:new-annual-period',
            profileId: $profile->id
        );

        $limit = $subscription->limit()->firstOrFail();

        $this->assertTrue($limit->period_started_at->isSameDay(Carbon::parse('2026-02-15')));
        $this->assertTrue($limit->period_renews_at->isSameDay(Carbon::parse('2026-03-15')));
        $this->assertSame(0, $limit->profiles_remaining);
        $this->assertSame(1, SubscriptionUse::where('idempotency_key', 'profile-created:new-annual-period')->firstOrFail()->profiles_used);
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

    private function createActiveSubscriptionFor(
        User $user,
        SubscriptionPlan $plan = SubscriptionPlan::Starter,
        mixed $renewsAt = null,
        mixed $periodStartedAt = null,
        mixed $periodRenewsAt = null,
        int $profilesRemaining = 1
    ): Subscription {
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan' => $plan,
            'started_at' => now()->subDay(),
            'renews_at' => $renewsAt ?? now()->addMonth(),
            'status' => SubscriptionStatus::First,
            'active' => true,
        ]);

        SubscriptionLimit::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'period_started_at' => $periodStartedAt ?? $subscription->started_at,
            'period_renews_at' => $periodRenewsAt ?? $subscription->renews_at,
            'profiles_remaining' => $profilesRemaining,
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
