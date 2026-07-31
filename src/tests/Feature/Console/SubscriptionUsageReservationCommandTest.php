<?php

namespace Tests\Feature\Console;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Enums\SubscriptionUsageType;
use App\Models\SubscriptionUse;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Tests\Support\CreatesSubscriptionScenarios;
use Tests\TestCase;

class SubscriptionUsageReservationCommandTest extends TestCase
{
    use CreatesSubscriptionScenarios;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_command_releases_only_stale_reservations_and_is_idempotent(): void
    {
        Carbon::setTestNow('2026-07-30 02:00:00');
        config(['subscriptions.usage_reservation_ttl_minutes' => 10]);
        $user = User::factory()->create();
        [$subscription] = $this->createConfiguredSubscription($user);
        $recorder = app(SubscriptionUsageRecorder::class);

        foreach (['stale', 'current', 'finalized'] as $key) {
            $recorder->reserve(
                userId: $user->id,
                usageType: SubscriptionUsageType::VoiceTtsCharacters,
                amounts: ['tts_characters' => 10],
                idempotencyKey: $key,
            );
        }
        $recorder->finalize('finalized');
        SubscriptionUse::query()
            ->where('idempotency_key', 'stale')
            ->update(['reserved_at' => now()->subMinutes(11)]);
        Log::spy();

        $this->artisan('subscriptions:release-stale-usage-reservations')
            ->expectsOutput('Stale subscription usage reservations released: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('subscription_uses', [
            'idempotency_key' => 'stale',
            'status' => SubscriptionUse::STATUS_RELEASED,
        ]);
        $this->assertDatabaseHas('subscription_uses', [
            'idempotency_key' => 'current',
            'status' => SubscriptionUse::STATUS_RESERVED,
        ]);
        $this->assertDatabaseHas('subscription_uses', [
            'idempotency_key' => 'finalized',
            'status' => SubscriptionUse::STATUS_FINALIZED,
        ]);
        $this->assertSame(19980, (int) $subscription->limit->fresh()->tts_characters_remaining);
        $this->assertSame(0.0, (float) $subscription->limit->fresh()->credits_remaining);
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $message === 'Stale subscription usage reservations released.'
                && $context['released_count'] === 1)
            ->once();

        $this->artisan('subscriptions:release-stale-usage-reservations')
            ->expectsOutput('Stale subscription usage reservations released: 0')
            ->assertSuccessful();
    }
}
