<?php

namespace Tests\Unit\Classes\Subscriptions;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Enums\CreditLedgerEntryType;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionUsageType;
use App\Exceptions\Subscriptions\SubscriptionEntitlementException;
use App\Models\CreditLedgerEntry;
use App\Models\CreditWallet;
use App\Models\SubscriptionUse;
use App\Models\User;
use Illuminate\Support\Carbon;
use Tests\Support\CreatesSubscriptionScenarios;
use Tests\TestCase;

class SubscriptionPurchasedCreditsTest extends TestCase
{
    use CreatesSubscriptionScenarios;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_plan_allowance_is_always_used_before_wallet_credits(): void
    {
        $user = User::factory()->create();
        [$subscription, $limit] = $this->createConfiguredSubscription($user);
        $wallet = $this->fundWallet($user, 1000);

        $use = app(SubscriptionUsageRecorder::class)->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::ChatMessageReceived,
            amounts: ['chat_messages' => 1],
            idempotencyKey: 'credits:plan-first',
        );

        $this->assertSame(999, $limit->fresh()->chat_messages_remaining);
        $this->assertSame(1000000, $wallet->fresh()->available_units);
        $this->assertSame(['chat_messages' => 1], $use->plan_covered);
        $this->assertSame([], $use->credit_covered);
        $this->assertSame(0, $use->purchased_credit_units);
    }

    public function test_chat_continues_with_wallet_after_included_limit_is_exhausted(): void
    {
        $user = User::factory()->create();
        [, $limit] = $this->createConfiguredSubscription($user);
        $limit->update(['chat_messages_remaining' => 0]);
        $wallet = $this->fundWallet($user, 1000);

        $use = app(SubscriptionUsageRecorder::class)->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::ChatMessageReceived,
            amounts: ['chat_messages' => 1],
            idempotencyKey: 'credits:chat-overage',
        );

        $this->assertSame(999830, $wallet->fresh()->available_units);
        $this->assertSame(170, $use->purchased_credit_units);
        $this->assertSame(0.17, $use->credits_used);
        $this->assertSame(['chat_messages' => 1], $use->credit_covered);
    }

    public function test_tts_splits_one_operation_between_plan_and_wallet(): void
    {
        $user = User::factory()->create();
        [, $limit] = $this->createConfiguredSubscription($user);
        $limit->update(['tts_characters_remaining' => 10]);
        $wallet = $this->fundWallet($user, 1000);

        $use = app(SubscriptionUsageRecorder::class)->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::VoiceTtsCharacters,
            amounts: ['tts_characters' => 30],
            idempotencyKey: 'credits:tts-split',
        );

        $this->assertSame(0, $limit->fresh()->tts_characters_remaining);
        $this->assertSame(999500, $wallet->fresh()->available_units);
        $this->assertSame(['tts_characters' => 10], $use->plan_covered);
        $this->assertSame(['tts_characters' => 20], $use->credit_covered);
        $this->assertSame(0.5, $use->credits_used);
    }

    public function test_incoming_audio_uses_credits_for_the_complete_transcription_when_one_audio_limit_is_exhausted(): void
    {
        $user = User::factory()->create();
        [, $limit] = $this->createConfiguredSubscription($user);
        $limit->update([
            'chat_messages_remaining' => 5,
            'incoming_audio_messages_remaining' => 0,
            'incoming_audio_seconds_remaining' => 100,
        ]);
        $wallet = $this->fundWallet($user, 1000);

        $use = app(SubscriptionUsageRecorder::class)->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::IncomingAudioMessage,
            amounts: [
                'chat_messages' => 1,
                'incoming_audio_messages' => 1,
                'incoming_audio_seconds' => 30,
            ],
            idempotencyKey: 'credits:audio-bundle',
        );

        $this->assertSame(4, $limit->fresh()->chat_messages_remaining);
        $this->assertSame(100, $limit->fresh()->incoming_audio_seconds_remaining);
        $this->assertSame(999250, $wallet->fresh()->available_units);
        $this->assertSame(['chat_messages' => 1], $use->plan_covered);
        $this->assertSame(0.75, $use->credits_used);
    }

    public function test_all_extendable_limits_can_continue_with_the_configured_tariffs(): void
    {
        $user = User::factory()->create();
        [, $limit] = $this->createConfiguredSubscription($user);
        $limit->update([
            'avatar_images_remaining' => 0,
            'avatar_video_seconds_remaining' => 0,
            'voice_clones_remaining' => 0,
            'tts_characters_remaining' => 0,
            'chat_messages_remaining' => 0,
            'incoming_audio_messages_remaining' => 0,
            'incoming_audio_seconds_remaining' => 0,
        ]);
        $wallet = $this->fundWallet($user, 1000);
        $recorder = app(SubscriptionUsageRecorder::class);

        $recorder->record($user->id, SubscriptionUsageType::ChatMessageReceived, ['chat_messages' => 1], 'all:chat');
        $recorder->record($user->id, SubscriptionUsageType::IncomingAudioMessage, [
            'chat_messages' => 1,
            'incoming_audio_messages' => 1,
            'incoming_audio_seconds' => 30,
        ], 'all:audio');
        $recorder->record($user->id, SubscriptionUsageType::VoiceTtsCharacters, ['tts_characters' => 20], 'all:tts');
        $recorder->record($user->id, SubscriptionUsageType::AvatarImageCreated, ['avatar_images' => 1], 'all:avatar-image');
        $recorder->record($user->id, SubscriptionUsageType::AvatarVideoCreated, ['avatar_video_seconds' => 5], 'all:avatar-video');
        $recorder->record($user->id, SubscriptionUsageType::VoiceCloned, ['voice_clones' => 1], 'all:voice');

        $this->assertSame(735910, $wallet->fresh()->available_units);
        $this->assertEqualsWithDelta(264.09, (float) SubscriptionUse::sum('credits_used'), 0.0001);
    }

    public function test_profile_limit_remains_hard_even_when_wallet_has_balance(): void
    {
        $user = User::factory()->create();
        [, $limit] = $this->createConfiguredSubscription($user);
        $limit->update(['profiles_remaining' => 0]);
        $this->fundWallet($user, 1000);

        $this->expectException(SubscriptionEntitlementException::class);
        $this->expectExceptionMessage('Subscription limit exceeded.');

        app(SubscriptionUsageRecorder::class)->record(
            userId: $user->id,
            usageType: SubscriptionUsageType::ProfileCreated,
            amounts: ['profiles' => 1],
            idempotencyKey: 'credits:profile-hard-limit',
        );
    }

    public function test_trial_cannot_consume_an_existing_purchased_credit_balance(): void
    {
        $user = User::factory()->create();
        [, $limit] = $this->createConfiguredSubscription(
            $user,
            status: SubscriptionStatus::Trialing,
        );
        $limit->update(['chat_messages_remaining' => 0]);
        $wallet = $this->fundWallet($user, 1000);

        try {
            app(SubscriptionUsageRecorder::class)->record(
                userId: $user->id,
                usageType: SubscriptionUsageType::ChatMessageReceived,
                amounts: ['chat_messages' => 1],
                idempotencyKey: 'credits:trial-wallet-blocked',
            );
            $this->fail('Expected trial purchased-credit consumption to be rejected.');
        } catch (SubscriptionEntitlementException $exception) {
            $this->assertArrayHasKey('chat_messages', $exception->errors());
            $this->assertSame(1000000, $wallet->fresh()->available_units);
            $this->assertDatabaseCount('subscription_uses', 0);
        }
    }

    public function test_insufficient_wallet_rejects_before_creating_usage(): void
    {
        $user = User::factory()->create();
        [, $limit] = $this->createConfiguredSubscription($user);
        $limit->update(['voice_clones_remaining' => 0]);
        $this->fundWallet($user, 99);

        try {
            app(SubscriptionUsageRecorder::class)->record(
                userId: $user->id,
                usageType: SubscriptionUsageType::VoiceCloned,
                amounts: ['voice_clones' => 1],
                idempotencyKey: 'credits:insufficient',
            );
            $this->fail('Expected insufficient purchased credits.');
        } catch (SubscriptionEntitlementException $exception) {
            $this->assertArrayHasKey('purchased_credits', $exception->errors());
            $this->assertDatabaseCount('subscription_uses', 0);
            $this->assertSame(99000, CreditWallet::where('user_id', $user->id)->firstOrFail()->available_units);
        }
    }

    public function test_wallet_reservation_finalize_and_release_are_atomic_and_idempotent(): void
    {
        $user = User::factory()->create();
        [, $limit] = $this->createConfiguredSubscription($user);
        $limit->update(['chat_messages_remaining' => 0]);
        $wallet = $this->fundWallet($user, 1);
        $recorder = app(SubscriptionUsageRecorder::class);

        $use = $recorder->reserve(
            userId: $user->id,
            usageType: SubscriptionUsageType::ChatMessageReceived,
            amounts: ['chat_messages' => 1],
            idempotencyKey: 'credits:lifecycle',
        );

        $this->assertSame(830, $wallet->fresh()->available_units);
        $this->assertSame(170, $wallet->fresh()->reserved_units);

        $recorder->finalize('credits:lifecycle');
        $recorder->finalize('credits:lifecycle');

        $this->assertSame(0, $wallet->fresh()->reserved_units);
        $this->assertSame(170, $wallet->fresh()->lifetime_consumed_units);
        $this->assertTrue($recorder->release('credits:lifecycle'));
        $this->assertFalse($recorder->release('credits:lifecycle'));
        $this->assertSame(1000, $wallet->fresh()->available_units);
        $this->assertSame(0, $wallet->fresh()->lifetime_consumed_units);
        $this->assertSame(1, CreditLedgerEntry::where('type', CreditLedgerEntryType::Reserve)->count());
        $this->assertSame(1, CreditLedgerEntry::where('type', CreditLedgerEntryType::Consume)->count());
        $this->assertSame(1, CreditLedgerEntry::where('type', CreditLedgerEntryType::Release)->count());
        $this->assertSame(SubscriptionUse::STATUS_RELEASED, $use->fresh()->status);
    }

    public function test_monthly_reset_preserves_wallet_and_restores_plan_first_order(): void
    {
        Carbon::setTestNow('2026-07-29 12:00:00');
        $user = User::factory()->create();
        [$subscription, $limit] = $this->createConfiguredSubscription(
            $user,
            startedAt: Carbon::parse('2026-06-15 10:00:00'),
            renewsAt: Carbon::parse('2026-08-15 10:00:00'),
        );
        $limit->update([
            'period_started_at' => Carbon::parse('2026-06-15 10:00:00'),
            'period_renews_at' => Carbon::parse('2026-07-15 10:00:00'),
            'chat_messages_remaining' => 0,
        ]);
        $wallet = $this->fundWallet($user, 1000);

        app(SubscriptionUsageRecorder::class)->record(
            $user->id,
            SubscriptionUsageType::ChatMessageReceived,
            ['chat_messages' => 1],
            'credits:after-reset',
        );

        $this->assertSame(999, $subscription->limit->fresh()->chat_messages_remaining);
        $this->assertSame(1000000, $wallet->fresh()->available_units);
        $this->assertSame(2, $subscription->usagePeriods()->count());
    }

    private function fundWallet(User $user, int $credits): CreditWallet
    {
        return CreditWallet::create([
            'user_id' => $user->id,
            'available_units' => $credits * 1000,
            'lifetime_purchased_units' => $credits * 1000,
        ]);
    }
}
