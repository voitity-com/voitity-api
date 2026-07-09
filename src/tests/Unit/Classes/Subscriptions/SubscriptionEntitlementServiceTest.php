<?php

namespace Tests\Unit\Classes\Subscriptions;

use App\Classes\Subscriptions\SubscriptionEntitlementService;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Subscriptions\SubscriptionEntitlementException;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SubscriptionEntitlementServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_rejects_usage_without_active_subscription(): void
    {
        $user = User::factory()->create();

        $this->expectException(SubscriptionEntitlementException::class);
        $this->expectExceptionMessage('Active subscription not found.');

        app(SubscriptionEntitlementService::class)->assertCanUse($user, ['profiles' => 1]);
    }

    public function test_it_rejects_usage_when_metric_limit_is_not_enough(): void
    {
        $user = User::factory()->create();
        $this->createActiveSubscriptionFor($user, profilesRemaining: 0);

        try {
            app(SubscriptionEntitlementService::class)->assertCanUse($user, ['profiles' => 1]);
            $this->fail('Expected subscription entitlement exception.');
        } catch (SubscriptionEntitlementException $exception) {
            $this->assertSame('Subscription limit exceeded.', $exception->getMessage());
            $this->assertArrayHasKey('profiles', $exception->errors());
        }
    }

    public function test_it_renews_expired_admin_plan_without_charge(): void
    {
        $user = User::factory()->create();
        $expiredSubscription = $this->createActiveSubscriptionFor(
            $user,
            plan: SubscriptionPlan::Admin,
            renewsAt: now()->subDay(),
            profilesRemaining: 2147483647
        );

        $subscription = app(SubscriptionEntitlementService::class)->assertCanUse($user, ['profiles' => 1]);

        $expiredSubscription->refresh();

        $this->assertFalse($expiredSubscription->active);
        $this->assertSame(SubscriptionStatus::Expired, $expiredSubscription->status);
        $this->assertSame(SubscriptionPlan::Admin, $subscription->plan);
        $this->assertTrue($subscription->active);
        $this->assertTrue($subscription->renews_at->isFuture());
        $this->assertSame('admin_grant', $subscription->billing_mode);
        $this->assertSame(2, Subscription::where('user_id', $user->id)->count());
    }

    public function test_it_resets_annual_monthly_limits_before_checking_capacity(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-02-16 10:00:00'));

        $user = User::factory()->create();
        $subscription = $this->createActiveSubscriptionFor(
            $user,
            plan: SubscriptionPlan::StarterAnnual,
            renewsAt: Carbon::parse('2027-01-15 10:00:00'),
            profilesRemaining: 0,
            periodStartedAt: Carbon::parse('2026-01-15 10:00:00'),
            periodRenewsAt: Carbon::parse('2026-02-15 10:00:00')
        );

        $checkedSubscription = app(SubscriptionEntitlementService::class)->assertCanUse($user, ['profiles' => 1]);
        $limit = $subscription->limit()->firstOrFail();

        $this->assertTrue($checkedSubscription->is($subscription));
        $this->assertTrue($limit->period_started_at->isSameDay(Carbon::parse('2026-02-15')));
        $this->assertTrue($limit->period_renews_at->isSameDay(Carbon::parse('2026-03-15')));
        $this->assertSame(1, $limit->profiles_remaining);
    }

    private function createActiveSubscriptionFor(
        User $user,
        SubscriptionPlan $plan = SubscriptionPlan::Starter,
        mixed $renewsAt = null,
        int $profilesRemaining = 1,
        mixed $periodStartedAt = null,
        mixed $periodRenewsAt = null
    ): Subscription {
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan' => $plan,
            'started_at' => now()->subDay(),
            'renews_at' => $renewsAt ?? now()->addMonth(),
            'status' => SubscriptionStatus::First,
            'active' => true,
            'billing_mode' => $plan === SubscriptionPlan::Admin ? 'admin_grant' : 'recurring',
        ]);

        SubscriptionLimit::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'period_started_at' => $periodStartedAt ?? $subscription->started_at,
            'period_renews_at' => $periodRenewsAt ?? $subscription->renews_at,
            'profiles_remaining' => $profilesRemaining,
            'avatar_images_remaining' => $plan === SubscriptionPlan::Admin ? 2147483647 : 1,
            'avatar_video_seconds_remaining' => $plan === SubscriptionPlan::Admin ? 2147483647 : 5,
            'voice_clones_remaining' => $plan === SubscriptionPlan::Admin ? 2147483647 : 1,
            'tts_characters_remaining' => $plan === SubscriptionPlan::Admin ? 2147483647 : 10000,
            'chat_messages_remaining' => $plan === SubscriptionPlan::Admin ? 2147483647 : 1000,
            'credits_remaining' => $plan === SubscriptionPlan::Admin ? 99999999.99 : 1000,
        ]);

        return $subscription;
    }
}
