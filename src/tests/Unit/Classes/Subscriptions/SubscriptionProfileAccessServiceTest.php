<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\Subscriptions;

use App\Classes\Subscriptions\SubscriptionProfileAccessService;
use App\Enums\ProfileStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class SubscriptionProfileAccessServiceTest extends TestCase
{
    public function test_it_deactivates_only_active_profiles_when_access_has_ended(): void
    {
        Log::spy();
        $user = User::factory()->create();
        $published = Profile::factory()->create([
            'user_id' => $user->id,
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);
        $draft = Profile::factory()->create([
            'user_id' => $user->id,
            'active' => false,
            'status' => ProfileStatus::Draft,
        ]);

        $deactivated = app(SubscriptionProfileAccessService::class)
            ->deactivateProfilesIfAccessEnded($user, 'test_access_ended', 91);

        $this->assertSame(1, $deactivated);
        $this->assertFalse((bool) $published->fresh()->active);
        $this->assertSame(ProfileStatus::Hidden, $published->fresh()->status);
        $this->assertFalse((bool) $draft->fresh()->active);
        $this->assertSame(ProfileStatus::Draft, $draft->fresh()->status);
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Profiles deactivated because subscription access ended.'
                && $context['profile_count'] === 1
                && $context['reason'] === 'test_access_ended'
                && $context['subscription_id'] === 91
                && $context['user_id'] === $user->id);
    }

    public function test_it_keeps_profiles_active_when_replacement_subscription_exists(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->create([
            'user_id' => $user->id,
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);
        $this->subscription($user, SubscriptionPlan::Starter, [
            'renews_at' => now()->addMonth(),
        ]);

        $deactivated = app(SubscriptionProfileAccessService::class)
            ->deactivateProfilesIfAccessEnded($user, 'old_subscription_ended', 92);

        $this->assertSame(0, $deactivated);
        $this->assertTrue((bool) $profile->fresh()->active);
        $this->assertSame(ProfileStatus::Published, $profile->fresh()->status);
    }

    public function test_starter_downgrade_hides_all_profiles_when_active_count_exceeds_limit(): void
    {
        $user = User::factory()->create();
        $first = Profile::factory()->create([
            'user_id' => $user->id,
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);
        $second = Profile::factory()->create([
            'user_id' => $user->id,
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);
        $subscription = $this->subscription($user, SubscriptionPlan::Starter);

        $deactivated = app(SubscriptionProfileAccessService::class)
            ->enforceActiveProfileLimit($subscription);

        $this->assertSame(2, $deactivated);
        $this->assertFalse((bool) $first->fresh()->active);
        $this->assertSame(ProfileStatus::Hidden, $first->fresh()->status);
        $this->assertFalse((bool) $second->fresh()->active);
        $this->assertSame(ProfileStatus::Hidden, $second->fresh()->status);
    }

    public function test_unlimited_plan_keeps_multiple_active_profiles(): void
    {
        $user = User::factory()->create();
        $first = Profile::factory()->create([
            'user_id' => $user->id,
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);
        $second = Profile::factory()->create([
            'user_id' => $user->id,
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);
        $subscription = $this->subscription($user, SubscriptionPlan::Admin);

        $deactivated = app(SubscriptionProfileAccessService::class)
            ->enforceActiveProfileLimit($subscription);

        $this->assertSame(0, $deactivated);
        $this->assertTrue((bool) $first->fresh()->active);
        $this->assertTrue((bool) $second->fresh()->active);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function subscription(
        User $user,
        SubscriptionPlan $plan,
        array $overrides = []
    ): Subscription {
        return Subscription::query()->create(array_merge([
            'user_id' => $user->id,
            'plan' => $plan,
            'started_at' => now()->subDay(),
            'renews_at' => now()->addMonth(),
            'status' => SubscriptionStatus::First,
            'active' => true,
            'cancel_at_period_end' => false,
        ], $overrides));
    }
}
