<?php

namespace App\Classes\Subscriptions;

use App\Enums\SubscriptionPlan;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionLimitPeriodService
{
    private const UNLIMITED_INTEGER = 2147483647;

    private const UNLIMITED_CREDITS = 99999999.99;

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
        return SubscriptionLimit::create($this->limitAttributes($subscription, $periodStartedAt));
    }

    private function resetLimitForPeriod(SubscriptionLimit $limit, Subscription $subscription, Carbon $periodStartedAt): void
    {
        $limit->fill($this->limitAttributes($subscription, $periodStartedAt));
        $limit->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function limitAttributes(Subscription $subscription, Carbon $periodStartedAt): array
    {
        $planConfig = config("subscriptions.plans.{$subscription->plan->value}", []);
        $limits = $planConfig['limits'] ?? [];
        $unlimited = (bool) ($planConfig['unlimited'] ?? false);
        $columns = [
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'period_started_at' => $periodStartedAt,
            'period_renews_at' => $this->periodEnd($subscription, $periodStartedAt),
            'credits_remaining' => $this->creditTotal($planConfig, $unlimited),
        ];

        foreach (self::METRIC_COLUMNS as $metric => $column) {
            $columns[$column] = $this->limitValue($limits[$metric] ?? null, $unlimited);
        }

        return $columns;
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

    /**
     * @param  array<string, mixed>  $planConfig
     */
    private function creditTotal(array $planConfig, bool $unlimited): float
    {
        $total = $planConfig['credits']['total'] ?? null;

        if ($total === null && $unlimited) {
            return self::UNLIMITED_CREDITS;
        }

        return round(max(0, (float) ($total ?? 0)), 2);
    }

    private function limitValue(mixed $value, bool $unlimited): int
    {
        if ($value === null && $unlimited) {
            return self::UNLIMITED_INTEGER;
        }

        return max(0, (int) ($value ?? 0));
    }
}
