<?php

namespace App\Classes\Subscriptions;

use App\Enums\SubscriptionPlan;
use App\Exceptions\Subscriptions\SubscriptionEntitlementException;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\User;

class SubscriptionEntitlementService
{
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

    public function __construct(
        private readonly SubscriptionPlanCatalog $planCatalog,
        private readonly SubscriptionRenewalService $renewalService,
        private readonly SubscriptionLimitPeriodService $limitPeriods
    ) {}

    /**
     * @param  array<string, int>  $amounts
     */
    public function assertCanUse(User|int $user, array $amounts): Subscription
    {
        $userId = $user instanceof User ? $user->id : $user;
        $subscription = $this->activeSubscriptionFor((int) $userId);
        $subscription = $this->renewalService->renewIfFree($subscription);

        if ($subscription->renews_at->isPast()) {
            $subscription->active = false;
            $subscription->save();

            throw new SubscriptionEntitlementException(
                'Active subscription has expired.',
                ['subscription' => ['Active subscription has expired.']]
            );
        }

        if ($this->planCatalog->isUnlimited($subscription->plan)) {
            return $subscription;
        }

        $limit = $this->limitPeriods->syncCurrentPeriod($subscription);

        if (! $limit instanceof SubscriptionLimit) {
            throw new SubscriptionEntitlementException(
                'Subscription limits were not found.',
                ['subscription' => ['Subscription limits were not found.']]
            );
        }

        $normalizedAmounts = $this->normalizeAmounts($amounts);
        $errors = $this->capacityErrors($subscription->plan, $limit, $normalizedAmounts);

        if ($errors !== []) {
            throw new SubscriptionEntitlementException('Subscription limit exceeded.', $errors);
        }

        return $subscription;
    }

    private function activeSubscriptionFor(int $userId): Subscription
    {
        $subscription = Subscription::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->with('limit', 'user')
            ->latest('started_at')
            ->first();

        if (! $subscription instanceof Subscription) {
            throw new SubscriptionEntitlementException(
                'Active subscription not found.',
                ['subscription' => ['Active subscription not found.']]
            );
        }

        return $subscription;
    }

    /**
     * @param  array<string, int>  $amounts
     * @return array<string, int>
     */
    private function normalizeAmounts(array $amounts): array
    {
        $normalized = [];

        foreach (self::METRIC_COLUMNS as $metric => $column) {
            $amount = max(0, (int) ($amounts[$metric] ?? 0));

            if ($amount > 0) {
                $normalized[$metric] = $amount;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, int>  $amounts
     * @return array<string, list<string>>
     */
    private function capacityErrors(SubscriptionPlan $plan, SubscriptionLimit $limit, array $amounts): array
    {
        $errors = [];

        foreach ($amounts as $metric => $amount) {
            $column = self::METRIC_COLUMNS[$metric];
            $remaining = (int) $limit->{$column};

            if ($remaining < $amount) {
                $errors[$metric] = ["Insufficient {$metric} quota."];
            }
        }

        $creditsUsed = $this->creditsUsedForPlan($plan, $amounts);

        if ($creditsUsed > 0 && (float) $limit->credits_remaining < $creditsUsed) {
            $errors['credits'] = ['Insufficient credits quota.'];
        }

        return $errors;
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
}
