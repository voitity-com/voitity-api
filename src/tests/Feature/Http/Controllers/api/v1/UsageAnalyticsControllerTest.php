<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Classes\Subscriptions\CreditAmount;
use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Enums\CreditLedgerEntryType;
use App\Enums\SubscriptionUsageType;
use App\Models\CreditLedgerEntry;
use App\Models\CreditWallet;
use App\Models\User;
use Tests\Support\CreatesSubscriptionScenarios;

class UsageAnalyticsControllerTest extends TestAPI
{
    use CreatesSubscriptionScenarios;

    public function test_user_can_filter_plan_and_purchased_credit_usage(): void
    {
        $user = User::factory()->create();
        [, $limit] = $this->createConfiguredSubscription($user);
        $limit->update(['chat_messages_remaining' => 0]);
        CreditWallet::create([
            'user_id' => $user->id,
            'available_units' => CreditAmount::creditsToUnits(1000),
        ]);
        app(SubscriptionUsageRecorder::class)->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::ChatMessageReceived,
            amounts: ['chat_messages' => 1],
            idempotencyKey: 'analytics:chat-credit',
        );
        $token = $user->createToken('analytics', ['subscription-limits:read'])->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/usage?from='
            .now()->startOfMonth()->toDateString()
            .'&to='.now()->toDateString()
            .'&group_by=day');

        $response->assertOk()
            ->assertJsonPath('data.range.group_by', 'day')
            ->assertJsonPath('data.wallet.available', 999.83)
            ->assertJsonPath('data.summary.credits.consumed', 0.17)
            ->assertJsonPath('data.summary.credits.reserved', 0)
            ->assertJsonPath('data.periods.0.included.chat_messages', 1000)
            ->assertJsonPath('data.periods.0.purchased_credits_used', 0.17)
            ->assertJsonPath(
                'data.series.0.services.chat_message_received.purchased_credits',
                0.17
            )
            ->assertJsonPath('data.series.0.credits.consumed', 0.17)
            ->assertJsonPath('data.series.0.credits.reserved', 0);
    }

    public function test_released_usage_is_excluded_from_analytics(): void
    {
        $user = User::factory()->create();
        [, $limit] = $this->createConfiguredSubscription($user);
        $limit->update(['chat_messages_remaining' => 0]);
        CreditWallet::create([
            'user_id' => $user->id,
            'available_units' => CreditAmount::creditsToUnits(1000),
        ]);
        $recorder = app(SubscriptionUsageRecorder::class);
        $recorder->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::ChatMessageReceived,
            amounts: ['chat_messages' => 1],
            idempotencyKey: 'analytics:released',
        );
        $recorder->release('analytics:released');
        $token = $user->createToken('analytics', ['subscription-limits:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/usage')
            ->assertOk()
            ->assertJsonPath('data.summary.credits.consumed', 0)
            ->assertJsonCount(0, 'data.series');
    }

    public function test_avatar_bundle_credits_are_reported_as_image_and_video_services(): void
    {
        $user = User::factory()->create();
        [, $limit] = $this->createConfiguredSubscription($user);
        $limit->update([
            'avatar_images_remaining' => 0,
            'avatar_video_seconds_remaining' => 0,
        ]);
        CreditWallet::create([
            'user_id' => $user->id,
            'available_units' => CreditAmount::creditsToUnits(1000),
        ]);
        app(SubscriptionUsageRecorder::class)->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::AvatarGenerated,
            amounts: ['avatar_images' => 1, 'avatar_video_seconds' => 2],
            idempotencyKey: 'analytics:avatar-bundle',
        );
        $token = $user->createToken('analytics', ['subscription-limits:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/usage?from='.now()->startOfMonth()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.summary.credits.consumed', 72.5)
            ->assertJsonPath('data.series.0.credits.consumed', 72.5)
            ->assertJsonPath('data.series.0.services.avatar_image_created.operations', 1)
            ->assertJsonPath('data.series.0.services.avatar_image_created.purchased_credits', 12.5)
            ->assertJsonPath('data.series.0.services.avatar_video_created.operations', 1)
            ->assertJsonPath('data.series.0.services.avatar_video_created.purchased_credits', 60);
    }

    public function test_usage_analytics_remains_available_without_an_active_subscription(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('analytics', ['subscription-limits:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/usage')
            ->assertOk()
            ->assertJsonPath('data.wallet.available', 0)
            ->assertJsonCount(0, 'data.periods');
    }

    public function test_plan_only_usage_does_not_create_a_purchased_credit_series_bucket(): void
    {
        $user = User::factory()->create();
        $this->createConfiguredSubscription($user);
        app(SubscriptionUsageRecorder::class)->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::ChatMessageReceived,
            amounts: ['chat_messages' => 1],
            idempotencyKey: 'analytics:plan-only',
        );
        $token = $user->createToken('analytics', ['subscription-limits:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/usage')
            ->assertOk()
            ->assertJsonPath('data.summary.credits.consumed', 0)
            ->assertJsonCount(0, 'data.series');
    }

    public function test_credit_purchases_and_reversals_are_included_in_each_series_bucket(): void
    {
        $user = User::factory()->create();
        $wallet = CreditWallet::create([
            'user_id' => $user->id,
            'available_units' => CreditAmount::creditsToUnits(800),
            'lifetime_purchased_units' => CreditAmount::creditsToUnits(1000),
        ]);
        CreditLedgerEntry::create([
            'credit_wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'type' => CreditLedgerEntryType::Purchase,
            'amount_units' => CreditAmount::creditsToUnits(1000),
            'available_units_after' => CreditAmount::creditsToUnits(1000),
            'reserved_units_after' => 0,
            'debt_units_after' => 0,
            'idempotency_key' => 'analytics:purchase',
            'occurred_at' => now(),
        ]);
        CreditLedgerEntry::create([
            'credit_wallet_id' => $wallet->id,
            'user_id' => $user->id,
            'type' => CreditLedgerEntryType::Reversal,
            'amount_units' => -CreditAmount::creditsToUnits(200),
            'available_units_after' => CreditAmount::creditsToUnits(800),
            'reserved_units_after' => 0,
            'debt_units_after' => 0,
            'idempotency_key' => 'analytics:reversal',
            'occurred_at' => now(),
        ]);
        $token = $user->createToken('analytics', ['subscription-limits:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/usage?from='.now()->toDateString().'&to='.now()->toDateString())
            ->assertOk()
            ->assertJsonPath('data.summary.credits.purchased', 1000)
            ->assertJsonPath('data.summary.credits.reversed', 200)
            ->assertJsonPath('data.series.0.credits.purchased', 1000)
            ->assertJsonPath('data.series.0.credits.reversed', 200);
    }

    public function test_usage_dates_and_buckets_respect_the_requested_timezone(): void
    {
        $user = User::factory()->create();
        [, $limit] = $this->createConfiguredSubscription($user);
        $limit->update(['chat_messages_remaining' => 0]);
        CreditWallet::create([
            'user_id' => $user->id,
            'available_units' => CreditAmount::creditsToUnits(1000),
        ]);
        $use = app(SubscriptionUsageRecorder::class)->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::ChatMessageReceived,
            amounts: ['chat_messages' => 1],
            idempotencyKey: 'analytics:bogota-midnight',
        );
        $use->update(['used_at' => '2026-07-30 04:30:00']);
        $token = $user->createToken('analytics', ['subscription-limits:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/usage?from=2026-07-29&to=2026-07-29&group_by=day&timezone=America%2FBogota')
            ->assertOk()
            ->assertJsonPath('data.range.timezone', 'America/Bogota')
            ->assertJsonPath('data.summary.credits.consumed', 0.17)
            ->assertJsonPath('data.series.0.bucket', '2026-07-29');
    }

    public function test_usage_timezone_must_be_valid(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('analytics', ['subscription-limits:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/usage?timezone=Not%2FA-Timezone')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('timezone');
    }

    public function test_usage_range_cannot_exceed_twenty_four_months(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('analytics', ['subscription-limits:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/usage?from=2023-01-01&to=2026-07-29')
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.from.0',
                'Usage analytics range cannot exceed 24 months.'
            );
    }
}
