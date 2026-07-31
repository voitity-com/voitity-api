<?php

namespace Tests\Unit\Classes\Subscriptions;

use App\Classes\Subscriptions\SubscriptionLimitPeriodService;
use App\Classes\Subscriptions\SubscriptionPlanAssigner;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SubscriptionLimitPeriodServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_annual_plan_creates_annual_billing_with_monthly_usage_limits(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 10:00:00'));

        $user = User::factory()->create();
        $subscription = (new SubscriptionPlanAssigner(new SubscriptionLimitPeriodService))
            ->assign($user, SubscriptionPlan::StarterAnnual);
        $limit = $subscription->limit()->firstOrFail();

        $this->assertSame(SubscriptionPlan::StarterAnnual, $subscription->plan);
        $this->assertTrue($subscription->renews_at->isSameDay(Carbon::parse('2027-01-15')));
        $this->assertTrue($subscription->next_billing_at->isSameDay(Carbon::parse('2027-01-15')));
        $this->assertTrue($limit->period_started_at->isSameDay(Carbon::parse('2026-01-15')));
        $this->assertTrue($limit->period_renews_at->isSameDay(Carbon::parse('2026-02-15')));
        $this->assertSame(1, $limit->profiles_remaining);
        $this->assertSame(1, $limit->avatar_images_remaining);
        $this->assertSame(5, $limit->avatar_video_seconds_remaining);
        $this->assertSame(1, $limit->voice_clones_remaining);
        $this->assertSame(20000, $limit->tts_characters_remaining);
        $this->assertSame(1000, $limit->chat_messages_remaining);
        $this->assertSame(500, $limit->incoming_audio_messages_remaining);
        $this->assertSame(15000, $limit->incoming_audio_seconds_remaining);
        $this->assertSame(0.0, $limit->credits_remaining);
    }

    public function test_it_resets_due_annual_usage_period_without_changing_billing_dates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-16 10:00:00'));

        $user = User::factory()->create();
        $subscription = $this->annualSubscription($user);
        SubscriptionLimit::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'period_started_at' => Carbon::parse('2026-01-15 10:00:00'),
            'period_renews_at' => Carbon::parse('2026-02-15 10:00:00'),
            'profiles_remaining' => 0,
            'avatar_images_remaining' => 0,
            'avatar_video_seconds_remaining' => 0,
            'voice_clones_remaining' => 0,
            'tts_characters_remaining' => 0,
            'chat_messages_remaining' => 0,
            'credits_remaining' => 0,
        ]);

        $limit = (new SubscriptionLimitPeriodService)->syncCurrentPeriod($subscription);
        $subscription->refresh();

        $this->assertTrue($subscription->renews_at->isSameDay(Carbon::parse('2027-01-15')));
        $this->assertTrue($subscription->next_billing_at->isSameDay(Carbon::parse('2027-01-15')));
        $this->assertTrue($limit->period_started_at->isSameDay(Carbon::parse('2026-02-15')));
        $this->assertTrue($limit->period_renews_at->isSameDay(Carbon::parse('2026-03-15')));
        $this->assertSame(1, $limit->profiles_remaining);
        $this->assertSame(0.0, $limit->credits_remaining);
    }

    public function test_it_resets_due_limit_periods_for_active_subscriptions_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-16 10:00:00'));

        $activeUser = User::factory()->create();
        $expiredUser = User::factory()->create();
        $activeSubscription = $this->annualSubscription($activeUser);
        $expiredSubscription = Subscription::create([
            'user_id' => $expiredUser->id,
            'plan' => SubscriptionPlan::StarterAnnual,
            'started_at' => Carbon::parse('2025-01-15 10:00:00'),
            'renews_at' => Carbon::parse('2026-01-15 10:00:00'),
            'status' => SubscriptionStatus::First,
            'active' => true,
            'billing_mode' => 'recurring',
            'next_billing_at' => Carbon::parse('2026-01-15 10:00:00'),
        ]);
        $this->exhaustedLimit($activeSubscription);
        $this->exhaustedLimit($expiredSubscription);

        $count = (new SubscriptionLimitPeriodService)->resetDueLimitPeriods();

        $this->assertSame(1, $count);
        $this->assertSame(1, $activeSubscription->limit()->firstOrFail()->profiles_remaining);
        $this->assertSame(0, $expiredSubscription->limit()->firstOrFail()->profiles_remaining);
    }

    private function annualSubscription(User $user): Subscription
    {
        return Subscription::create([
            'user_id' => $user->id,
            'plan' => SubscriptionPlan::StarterAnnual,
            'started_at' => Carbon::parse('2026-01-15 10:00:00'),
            'renews_at' => Carbon::parse('2027-01-15 10:00:00'),
            'status' => SubscriptionStatus::First,
            'active' => true,
            'billing_mode' => 'recurring',
            'next_billing_at' => Carbon::parse('2027-01-15 10:00:00'),
        ]);
    }

    private function exhaustedLimit(Subscription $subscription): SubscriptionLimit
    {
        return SubscriptionLimit::create([
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'period_started_at' => Carbon::parse('2026-01-15 10:00:00'),
            'period_renews_at' => Carbon::parse('2026-02-15 10:00:00'),
            'profiles_remaining' => 0,
            'avatar_images_remaining' => 0,
            'avatar_video_seconds_remaining' => 0,
            'voice_clones_remaining' => 0,
            'tts_characters_remaining' => 0,
            'chat_messages_remaining' => 0,
            'credits_remaining' => 0,
        ]);
    }
}
