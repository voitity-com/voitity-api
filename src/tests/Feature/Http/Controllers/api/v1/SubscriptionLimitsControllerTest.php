<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionUsageType;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\SubscriptionUse;
use App\Models\User;

class SubscriptionLimitsControllerTest extends TestAPI
{
    private const ENDPOINT = '/api/subscription/limits';

    public function test_unauthenticated_user_can_not_read_subscription_limits(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->faker->word())
            ->getJson(self::ENDPOINT);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_without_subscription_limits_read_ability_can_not_read_subscription_limits(): void
    {
        $user = User::factory()->create();
        $this->createActiveStarterSubscriptionFor($user);
        $token = $user->createToken('test-token', ['profile:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT);

        $response->assertStatus(403);
    }

    public function test_user_can_read_active_subscription_limits_and_usage(): void
    {
        $user = User::factory()->create();
        $subscription = $this->createActiveStarterSubscriptionFor($user);

        SubscriptionUse::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'profile_id' => null,
            'usage_type' => SubscriptionUsageType::ProfileCreated,
            'idempotency_key' => 'profile-created:1',
            'profiles_used' => 1,
            'used_at' => '2026-06-17 10:00:00',
        ]);

        SubscriptionUse::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'profile_id' => null,
            'usage_type' => SubscriptionUsageType::AvatarVideoCreated,
            'idempotency_key' => 'avatar-video:1',
            'avatar_video_seconds_used' => 2,
            'used_at' => '2026-06-17 11:00:00',
        ]);

        SubscriptionUse::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'profile_id' => null,
            'usage_type' => SubscriptionUsageType::VoiceTtsCharacters,
            'idempotency_key' => 'voice-tts:1',
            'tts_characters_used' => 1000,
            'used_at' => '2026-06-17 12:00:00',
        ]);

        $token = $user->createToken('test-token', ['subscription-limits:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Subscription limits retrieved successfully.');
        $response->assertJsonPath('data.subscription.id', $subscription->id);
        $response->assertJsonPath('data.subscription.user_id', $user->id);
        $response->assertJsonPath('data.subscription.plan', 'starter');
        $response->assertJsonPath('data.subscription.plan_name', 'Starter');
        $response->assertJsonPath('data.subscription.price_usd', 7);
        $response->assertJsonPath('data.subscription.currency', 'USD');
        $response->assertJsonPath('data.subscription.interval', 'monthly');
        $response->assertJsonPath('data.subscription.status', 'first');
        $response->assertJsonPath('data.subscription.active', true);
        $response->assertJsonPath('data.limits.profiles.included', 1);
        $response->assertJsonPath('data.limits.profiles.remaining', 0);
        $response->assertJsonPath('data.limits.profiles.used', 1);
        $response->assertJsonPath('data.limits.avatar_video_seconds.included', 5);
        $response->assertJsonPath('data.limits.avatar_video_seconds.remaining', 3);
        $response->assertJsonPath('data.limits.avatar_video_seconds.used', 2);
        $response->assertJsonPath('data.limits.tts_characters.included', 10000);
        $response->assertJsonPath('data.limits.tts_characters.remaining', 9000);
        $response->assertJsonPath('data.limits.tts_characters.used', 1000);
        $response->assertJsonPath('data.usage.totals.profiles', 1);
        $response->assertJsonPath('data.usage.totals.avatar_video_seconds', 2);
        $response->assertJsonPath('data.usage.totals.tts_characters', 1000);

        $usageByType = collect($response->json('data.usage.by_type'))->keyBy('usage_type');

        $this->assertSame(1, $usageByType['profile_created']['records_count']);
        $this->assertSame(1, $usageByType['profile_created']['used']['profiles']);
        $this->assertSame(1, $usageByType['avatar_video_created']['records_count']);
        $this->assertSame(2, $usageByType['avatar_video_created']['used']['avatar_video_seconds']);
        $this->assertSame(1, $usageByType['voice_tts_characters']['records_count']);
        $this->assertSame(1000, $usageByType['voice_tts_characters']['used']['tts_characters']);
    }

    public function test_user_without_active_subscription_gets_not_found(): void
    {
        $user = User::factory()->create();
        $subscription = $this->createActiveStarterSubscriptionFor($user);
        $subscription->update([
            'active' => false,
            'status' => SubscriptionStatus::Expired,
        ]);
        $token = $user->createToken('test-token', ['subscription-limits:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Active subscription not found.');
    }

    public function test_endpoint_only_returns_subscription_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->createActiveStarterSubscriptionFor($otherUser);
        $token = $user->createToken('test-token', ['subscription-limits:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson(self::ENDPOINT);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Active subscription not found.');
    }

    private function createActiveStarterSubscriptionFor(User $user): Subscription
    {
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan' => SubscriptionPlan::Starter,
            'started_at' => '2026-06-17 00:00:00',
            'renews_at' => '2026-07-17 00:00:00',
            'status' => SubscriptionStatus::First,
            'active' => true,
        ]);

        SubscriptionLimit::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'period_started_at' => $subscription->started_at,
            'period_renews_at' => $subscription->renews_at,
            'profiles_remaining' => 0,
            'avatar_images_remaining' => 1,
            'avatar_video_seconds_remaining' => 3,
            'voice_clones_remaining' => 1,
            'tts_characters_remaining' => 9000,
            'chat_messages_remaining' => 1000,
        ]);

        return $subscription;
    }
}
