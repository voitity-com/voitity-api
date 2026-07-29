<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ProfileStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SubscriptionExpirationCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_cancelled_subscription_keeps_profiles_until_service_end_then_hides_them(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-28 09:59:00'));
        $user = User::factory()->create();
        $subscription = $this->cancelledSubscription($user, now()->addMinute());
        $profile = Profile::factory()->create([
            'user_id' => $user->id,
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);

        $this->artisan('subscriptions:expire-ended')
            ->expectsOutput('Ended subscriptions expired: 0')
            ->assertSuccessful();

        $this->assertTrue((bool) $subscription->fresh()->active);
        $this->assertTrue((bool) $profile->fresh()->active);

        Carbon::setTestNow(Carbon::parse('2026-08-28 10:00:00'));

        $this->artisan('subscriptions:expire-ended')
            ->expectsOutput('Ended subscriptions expired: 1')
            ->assertSuccessful();

        $this->assertFalse((bool) $subscription->fresh()->active);
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->fresh()->status);
        $this->assertFalse((bool) $profile->fresh()->active);
        $this->assertSame(ProfileStatus::Hidden, $profile->fresh()->status);

        $this->artisan('subscriptions:expire-ended')
            ->expectsOutput('Ended subscriptions expired: 0')
            ->assertSuccessful();
    }

    public function test_ending_old_subscription_does_not_hide_profiles_when_replacement_is_active(): void
    {
        Mail::fake();
        Carbon::setTestNow(Carbon::parse('2026-08-28 10:00:00'));
        $user = User::factory()->create();
        $oldSubscription = $this->cancelledSubscription($user, now());
        $this->activeSubscription($user, now()->addYear());
        $profile = Profile::factory()->create([
            'user_id' => $user->id,
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);

        $this->artisan('subscriptions:expire-ended')
            ->expectsOutput('Ended subscriptions expired: 1')
            ->assertSuccessful();

        $this->assertFalse((bool) $oldSubscription->fresh()->active);
        $this->assertSame(SubscriptionStatus::Cancelled, $oldSubscription->fresh()->status);
        $this->assertTrue((bool) $profile->fresh()->active);
        $this->assertSame(ProfileStatus::Published, $profile->fresh()->status);
    }

    private function cancelledSubscription(User $user, Carbon $renewsAt): Subscription
    {
        return Subscription::query()->create([
            'user_id' => $user->id,
            'plan' => SubscriptionPlan::Starter,
            'billing_mode' => 'recurring',
            'started_at' => $renewsAt->copy()->subMonth(),
            'renews_at' => $renewsAt,
            'status' => SubscriptionStatus::First,
            'active' => true,
            'cancel_at_period_end' => true,
            'cancelled_at' => $renewsAt->copy()->subWeek(),
        ]);
    }

    private function activeSubscription(User $user, Carbon $renewsAt): Subscription
    {
        return Subscription::query()->create([
            'user_id' => $user->id,
            'plan' => SubscriptionPlan::Starter,
            'billing_mode' => 'recurring',
            'started_at' => now(),
            'renews_at' => $renewsAt,
            'status' => SubscriptionStatus::Renewed,
            'active' => true,
            'cancel_at_period_end' => false,
        ]);
    }
}
