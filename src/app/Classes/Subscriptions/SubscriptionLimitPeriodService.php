<?php

namespace App\Classes\Subscriptions;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\SubscriptionUsagePeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionLimitPeriodService
{
    private const UNLIMITED_INTEGER = 2147483647;

    /**
     * @var array<string, string>
     */
    private const METRIC_COLUMNS = [
        'profiles' => 'profiles_remaining',
        'avatar_images' => 'avatar_images_remaining',
        'avatar_video_seconds' => 'avatar_video_seconds_remaining',
        'voice_clones' => 'voice_clones_remaining',
        'tts_characters' => 'tts_characters_remaining',
        'chat_messages' => 'chat_messages_remaining',
        'incoming_audio_messages' => 'incoming_audio_messages_remaining',
        'incoming_audio_seconds' => 'incoming_audio_seconds_remaining',
    ];

    public function createInitialLimit(Subscription $subscription): SubscriptionLimit
    {
        return $this->createLimitForPeriod($subscription, Carbon::parse($subscription->started_at));
    }

    public function syncCurrentPeriod(Subscription $subscription, ?Carbon $now = null): SubscriptionLimit
    {
        $now ??= now();

        return DB::transaction(function () use ($now, $subscription): SubscriptionLimit {
            /** @var Subscription $lockedSubscription */
            $lockedSubscription = Subscription::query()
                ->whereKey($subscription->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var SubscriptionLimit|null $limit */
            $limit = $lockedSubscription->limit()->lockForUpdate()->first();

            if (! $limit instanceof SubscriptionLimit) {
                return $this->createLimitForPeriod($lockedSubscription, $this->currentPeriodStart($lockedSubscription, $now));
            }

            if ($lockedSubscription->renews_at->isFuture() && $limit->period_renews_at->lessThanOrEqualTo($now)) {
                $this->resetLimitForPeriod($limit, $lockedSubscription, $this->currentPeriodStart($lockedSubscription, $now));
            }

            return $limit->fresh();
        });
    }

    public function resetDueLimitPeriods(?Carbon $now = null): int
    {
        $now ??= now();
        $count = 0;

        SubscriptionLimit::query()
            ->with('subscription')
            ->where('period_renews_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($limits) use (&$count, $now): void {
                foreach ($limits as $limit) {
                    if (! $limit instanceof SubscriptionLimit || ! $limit->subscription instanceof Subscription) {
                        continue;
                    }

                    $previousPeriodStartedAt = $limit->period_started_at;
                    $syncedLimit = $this->syncCurrentPeriod($limit->subscription, $now);

                    if ($syncedLimit->period_started_at->greaterThan($previousPeriodStartedAt)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    private function createLimitForPeriod(Subscription $subscription, Carbon $periodStartedAt): SubscriptionLimit
    {
        $attributes = $this->limitAttributes($subscription, $periodStartedAt);
        $attributes['usage_period_id'] = $this->usagePeriodFor($subscription, $attributes)->id;

        return SubscriptionLimit::create($attributes);
    }

    private function resetLimitForPeriod(SubscriptionLimit $limit, Subscription $subscription, Carbon $periodStartedAt): void
    {
        $attributes = $this->limitAttributes($subscription, $periodStartedAt);
        $attributes['usage_period_id'] = $this->usagePeriodFor($subscription, $attributes)->id;
        $limit->fill($attributes);
        $limit->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function limitAttributes(Subscription $subscription, Carbon $periodStartedAt): array
    {
        $planConfig = config("subscriptions.plans.{$subscription->plan->value}", []);
        $usageConfig = $this->usageConfig($subscription, $planConfig);
        $limits = $usageConfig['limits'] ?? [];
        $unlimited = (bool) ($planConfig['unlimited'] ?? false);
        $columns = [
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'period_started_at' => $periodStartedAt,
            'period_renews_at' => $this->periodEnd($subscription, $periodStartedAt),
            'credits_remaining' => 0,
        ];

        foreach (self::METRIC_COLUMNS as $metric => $column) {
            $columns[$column] = $this->limitValue($limits[$metric] ?? null, $unlimited);
        }

        if (! $unlimited) {
            $profileLimit = max(0, (int) ($limits['profiles'] ?? 0));
            $profileCount = Profile::query()
                ->where('user_id', $subscription->user_id)
                ->count();
            $columns['profiles_remaining'] = max(0, $profileLimit - $profileCount);
        }

        return $columns;
    }

    /**
     * @param  array<string, mixed>  $limitAttributes
     */
    private function usagePeriodFor(Subscription $subscription, array $limitAttributes): SubscriptionUsagePeriod
    {
        $limitsSnapshot = [];

        foreach (self::METRIC_COLUMNS as $metric => $column) {
            $limitsSnapshot[$metric] = (int) ($limitAttributes[$column] ?? 0);
        }

        return SubscriptionUsagePeriod::firstOrCreate([
            'subscription_id' => $subscription->id,
            'period_started_at' => $limitAttributes['period_started_at'],
        ], [
            'user_id' => $subscription->user_id,
            'plan' => $subscription->plan,
            'period_renews_at' => $limitAttributes['period_renews_at'],
            'limits_snapshot' => $limitsSnapshot,
        ]);
    }

    private function currentPeriodStart(Subscription $subscription, Carbon $now): Carbon
    {
        $periodStart = Carbon::parse($subscription->started_at);

        while (true) {
            $periodEnd = $this->periodEnd($subscription, $periodStart);

            if ($periodEnd->greaterThan($now) || $periodEnd->equalTo($subscription->renews_at)) {
                return $periodStart;
            }

            $periodStart = $periodEnd;
        }
    }

    private function periodEnd(Subscription $subscription, Carbon $periodStartedAt): Carbon
    {
        $periodEnd = match ($this->usageInterval($subscription->plan)) {
            'annual', 'annually', 'year', 'yearly' => $periodStartedAt->copy()->addYearNoOverflow(),
            default => $periodStartedAt->copy()->addMonthNoOverflow(),
        };

        $subscriptionRenewsAt = Carbon::parse($subscription->renews_at);

        return $periodEnd->greaterThan($subscriptionRenewsAt) ? $subscriptionRenewsAt : $periodEnd;
    }

    private function usageInterval(SubscriptionPlan $plan): string
    {
        return (string) config("subscriptions.plans.{$plan->value}.usage_interval", 'monthly');
    }

    private function limitValue(mixed $value, bool $unlimited): int
    {
        if ($value === null && $unlimited) {
            return self::UNLIMITED_INTEGER;
        }

        return max(0, (int) ($value ?? 0));
    }

    /**
     * @param  array<string, mixed>  $planConfig
     * @return array<string, mixed>
     */
    private function usageConfig(Subscription $subscription, array $planConfig): array
    {
        if ($subscription->status === SubscriptionStatus::Trialing) {
            return array_replace_recursive($planConfig, [
                'limits' => config('subscriptions.trial.limits', []),
                'credits' => config('subscriptions.trial.credits', []),
            ]);
        }

        return $planConfig;
    }
}
