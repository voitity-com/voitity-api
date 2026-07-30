<?php

namespace Tests\Unit\Classes\Subscriptions;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\User;
use Tests\Support\CreatesSubscriptionScenarios;
use Tests\TestCase;

class SubscriptionPlanLimitContractTest extends TestCase
{
    use CreatesSubscriptionScenarios;

    /**
     * @var array<string, string>
     */
    private const LIMIT_COLUMNS = [
        'profiles' => 'profiles_remaining',
        'avatar_images' => 'avatar_images_remaining',
        'avatar_video_seconds' => 'avatar_video_seconds_remaining',
        'voice_clones' => 'voice_clones_remaining',
        'tts_characters' => 'tts_characters_remaining',
        'chat_messages' => 'chat_messages_remaining',
        'incoming_audio_messages' => 'incoming_audio_messages_remaining',
        'incoming_audio_seconds' => 'incoming_audio_seconds_remaining',
    ];

    public function test_every_active_plan_creates_usage_limits_from_its_configuration(): void
    {
        foreach (config('subscriptions.plans', []) as $planId => $planConfig) {
            if (($planConfig['active'] ?? false) !== true) {
                continue;
            }

            $plan = SubscriptionPlan::tryFrom((string) $planId);
            $this->assertInstanceOf(SubscriptionPlan::class, $plan, "Active plan [{$planId}] needs an enum case.");
            [$subscription, $limit] = $this->createConfiguredSubscription(
                User::factory()->create(),
                $plan
            );

            foreach (self::LIMIT_COLUMNS as $metric => $column) {
                $this->assertSame(
                    (int) data_get($planConfig, "limits.{$metric}"),
                    (int) $limit->{$column},
                    "Plan [{$planId}] did not initialize [{$metric}] from its configuration."
                );
            }

            $this->assertEqualsWithDelta(
                (float) data_get($planConfig, 'credits.total', 0),
                (float) $limit->credits_remaining,
                0.000001
            );
            $expectedBillingEnd = match ((string) data_get($planConfig, 'interval', 'monthly')) {
                'annual', 'annually', 'year', 'yearly' => $subscription->started_at->copy()->addYearNoOverflow(),
                default => $subscription->started_at->copy()->addMonthNoOverflow(),
            };
            $this->assertTrue($subscription->renews_at->equalTo($expectedBillingEnd));
            $this->assertTrue($limit->period_renews_at->lessThanOrEqualTo($subscription->renews_at));
        }
    }

    public function test_trial_limits_are_initialized_from_the_trial_configuration(): void
    {
        [$subscription, $limit] = $this->createConfiguredSubscription(
            User::factory()->create(),
            SubscriptionPlan::Starter,
            SubscriptionStatus::Trialing
        );

        foreach (self::LIMIT_COLUMNS as $metric => $column) {
            $this->assertSame(
                (int) config("subscriptions.trial.limits.{$metric}"),
                (int) $limit->{$column}
            );
        }

        $this->assertEqualsWithDelta(
            (float) config('subscriptions.trial.credits.total'),
            (float) $limit->credits_remaining,
            0.000001
        );
        $this->assertTrue(
            $subscription->renews_at->equalTo(
                $subscription->started_at->copy()->addDays((int) config('subscriptions.trial.days'))
            )
        );
    }
}
