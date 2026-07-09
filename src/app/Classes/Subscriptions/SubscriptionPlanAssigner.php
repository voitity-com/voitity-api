<?php

namespace App\Classes\Subscriptions;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionPlanAssigner
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

    public function assign(User $user, SubscriptionPlan $plan): Subscription
    {
        return DB::transaction(function () use ($user, $plan): Subscription {
            /** @var User $lockedUser */
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            $previousSubscription = $lockedUser
                ->subscriptions()
                ->where('active', true)
                ->orderByDesc('started_at')
                ->lockForUpdate()
                ->first();

            if ($previousSubscription) {
                $previousSubscription->status = SubscriptionStatus::Expired;
                $previousSubscription->active = false;
                $previousSubscription->save();
            }

            $startedAt = now();

            /** @var Subscription $subscription */
            $subscription = $lockedUser->subscriptions()->create([
                'plan' => $plan,
                'started_at' => $startedAt,
                'renews_at' => $this->renewsAt($plan, $startedAt),
                'status' => $previousSubscription ? SubscriptionStatus::Renewed : SubscriptionStatus::First,
                'active' => true,
            ]);

            $this->createLimit($subscription);

            return $subscription;
        });
    }

    private function renewsAt(SubscriptionPlan $plan, Carbon $startedAt): Carbon
    {
        $interval = config("subscriptions.plans.{$plan->value}.interval", 'monthly');

        return match ($interval) {
            'yearly', 'annual', 'annually' => $startedAt->copy()->addYear(),
            default => $startedAt->copy()->addMonth(),
        };
    }

    private function createLimit(Subscription $subscription): SubscriptionLimit
    {
        $planConfig = config("subscriptions.plans.{$subscription->plan->value}", []);
        $limits = $planConfig['limits'] ?? [];
        $unlimited = (bool) ($planConfig['unlimited'] ?? false);
        $columns = [
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'period_started_at' => Carbon::parse($subscription->started_at),
            'period_renews_at' => Carbon::parse($subscription->renews_at),
            'credits_remaining' => $this->creditTotal($planConfig, $unlimited),
        ];

        foreach (self::METRIC_COLUMNS as $metric => $column) {
            $columns[$column] = $this->limitValue($limits[$metric] ?? null, $unlimited);
        }

        return SubscriptionLimit::create($columns);
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
