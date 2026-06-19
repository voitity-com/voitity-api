<?php

namespace App\Classes\Subscriptions;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionUsageType;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\SubscriptionUse;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionUsageRecorder
{
    /**
     * @var array<string, array{limit: string, use: string}>
     */
    private const METRIC_COLUMNS = [
        'profiles' => [
            'limit' => 'profiles_remaining',
            'use' => 'profiles_used',
        ],
        'avatar_images' => [
            'limit' => 'avatar_images_remaining',
            'use' => 'avatar_images_used',
        ],
        'avatar_video_seconds' => [
            'limit' => 'avatar_video_seconds_remaining',
            'use' => 'avatar_video_seconds_used',
        ],
        'voice_clones' => [
            'limit' => 'voice_clones_remaining',
            'use' => 'voice_clones_used',
        ],
        'tts_characters' => [
            'limit' => 'tts_characters_remaining',
            'use' => 'tts_characters_used',
        ],
        'chat_messages' => [
            'limit' => 'chat_messages_remaining',
            'use' => 'chat_messages_used',
        ],
    ];

    /**
     * @param  array<string, int>  $amounts
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        int $userId,
        SubscriptionUsageType $usageType,
        array $amounts,
        string $idempotencyKey,
        ?int $profileId = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        array $metadata = []
    ): SubscriptionUse {
        $existingUse = SubscriptionUse::where('idempotency_key', $idempotencyKey)->first();

        if ($existingUse) {
            return $existingUse;
        }

        return DB::transaction(function () use (
            $userId,
            $usageType,
            $amounts,
            $idempotencyKey,
            $profileId,
            $sourceType,
            $sourceId,
            $metadata
        ) {
            $existingUse = SubscriptionUse::where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingUse) {
                return $existingUse;
            }

            /** @var User $user */
            $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $subscription = $this->currentSubscriptionFor($user);
            $limit = $this->currentLimitFor($subscription);
            $normalizedAmounts = $this->normalizeAmounts($amounts);
            $creditsUsed = $this->creditsUsedForPlan($subscription->plan, $normalizedAmounts);

            $use = SubscriptionUse::create(array_merge([
                'subscription_id' => $subscription->id,
                'user_id' => $user->id,
                'profile_id' => $profileId,
                'usage_type' => $usageType,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'idempotency_key' => $idempotencyKey,
                'metadata' => $metadata,
                'used_at' => now(),
            ], $this->usageColumns($normalizedAmounts, $creditsUsed)));

            foreach ($normalizedAmounts as $metric => $amount) {
                $limitColumn = self::METRIC_COLUMNS[$metric]['limit'];
                $limit->{$limitColumn} = max(0, ((int) $limit->{$limitColumn}) - $amount);
            }

            $limit->credits_remaining = round(max(0, ((float) $limit->credits_remaining) - $creditsUsed), 2);
            $limit->save();

            return $use;
        });
    }

    private function currentSubscriptionFor(User $user): Subscription
    {
        /** @var Subscription|null $subscription */
        $subscription = $user->subscriptions()
            ->where('active', true)
            ->orderByDesc('started_at')
            ->lockForUpdate()
            ->first();

        if ($subscription && $subscription->renews_at->isFuture()) {
            return $subscription;
        }

        $previousPlan = $subscription?->plan ?? $this->defaultPlan();

        if ($subscription) {
            $subscription->status = SubscriptionStatus::Expired;
            $subscription->active = false;
            $subscription->save();
        }

        return $this->createSubscription(
            user: $user,
            plan: $previousPlan,
            status: $subscription ? SubscriptionStatus::Renewed : SubscriptionStatus::First
        );
    }

    private function currentLimitFor(Subscription $subscription): SubscriptionLimit
    {
        /** @var SubscriptionLimit|null $limit */
        $limit = $subscription->limit()->lockForUpdate()->first();

        if ($limit) {
            return $limit;
        }

        return $this->createLimit($subscription);
    }

    private function createSubscription(
        User $user,
        SubscriptionPlan $plan,
        SubscriptionStatus $status
    ): Subscription {
        $startedAt = now();
        $renewsAt = $startedAt->copy()->addMonth();

        /** @var Subscription $subscription */
        $subscription = $user->subscriptions()->create([
            'plan' => $plan,
            'started_at' => $startedAt,
            'renews_at' => $renewsAt,
            'status' => $status,
            'active' => true,
        ]);

        $this->createLimit($subscription);

        return $subscription;
    }

    private function createLimit(Subscription $subscription): SubscriptionLimit
    {
        $limits = $this->limitsForPlan($subscription->plan);

        return SubscriptionLimit::create(array_merge([
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'period_started_at' => Carbon::parse($subscription->started_at),
            'period_renews_at' => Carbon::parse($subscription->renews_at),
        ], $this->limitColumns($subscription->plan, $limits)));
    }

    /**
     * @return array<string, int>
     */
    private function normalizeAmounts(array $amounts): array
    {
        $normalized = [];

        foreach (array_keys(self::METRIC_COLUMNS) as $metric) {
            $normalized[$metric] = max(0, (int) ($amounts[$metric] ?? 0));
        }

        return $normalized;
    }

    /**
     * @param  array<string, int>  $amounts
     * @return array<string, int|float>
     */
    private function usageColumns(array $amounts, float $creditsUsed): array
    {
        $columns = [];

        foreach ($amounts as $metric => $amount) {
            $columns[self::METRIC_COLUMNS[$metric]['use']] = $amount;
        }

        $columns['credits_used'] = $creditsUsed;

        return $columns;
    }

    /**
     * @param  array<string, int>  $limits
     * @return array<string, int|float>
     */
    private function limitColumns(SubscriptionPlan $plan, array $limits): array
    {
        $columns = [];

        foreach (array_keys(self::METRIC_COLUMNS) as $metric) {
            $columns[self::METRIC_COLUMNS[$metric]['limit']] = max(0, (int) ($limits[$metric] ?? 0));
        }

        $columns['credits_remaining'] = $this->creditTotalForPlan($plan);

        return $columns;
    }

    /**
     * @param  array<string, int>  $amounts
     */
    private function creditsUsedForPlan(SubscriptionPlan $plan, array $amounts): float
    {
        $allocations = config("subscriptions.plans.{$plan->value}.credits.allocations", []);
        $creditsUsed = 0.0;

        foreach ($amounts as $metric => $amount) {
            if ($amount <= 0 || ! isset($allocations[$metric])) {
                continue;
            }

            $credits = (float) ($allocations[$metric]['credits'] ?? 0);
            $units = (float) ($allocations[$metric]['units'] ?? 0);

            if ($credits <= 0 || $units <= 0) {
                continue;
            }

            $creditsUsed += $amount * ($credits / $units);
        }

        return round($creditsUsed, 2);
    }

    private function creditTotalForPlan(SubscriptionPlan $plan): float
    {
        return round((float) config("subscriptions.plans.{$plan->value}.credits.total", 0), 2);
    }

    /**
     * @return array<string, int>
     */
    private function limitsForPlan(SubscriptionPlan $plan): array
    {
        return config("subscriptions.plans.{$plan->value}.limits", []);
    }

    private function defaultPlan(): SubscriptionPlan
    {
        $plan = config('subscriptions.default_plan', SubscriptionPlan::Starter->value);

        return SubscriptionPlan::tryFrom((string) $plan) ?? SubscriptionPlan::Starter;
    }
}
