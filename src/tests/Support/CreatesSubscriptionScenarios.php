<?php

namespace Tests\Support;

use App\Classes\Subscriptions\SubscriptionLimitPeriodService;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\User;
use Illuminate\Support\Carbon;

trait CreatesSubscriptionScenarios
{
    /**
     * @return array{Subscription, SubscriptionLimit}
     */
    protected function createConfiguredSubscription(
        User $user,
        SubscriptionPlan $plan = SubscriptionPlan::Starter,
        SubscriptionStatus $status = SubscriptionStatus::First,
        bool $active = true,
        ?Carbon $startedAt = null,
        ?Carbon $renewsAt = null,
    ): array {
        $startedAt ??= now()->subDay();
        $renewsAt ??= $this->scenarioRenewalDate($plan, $status, $startedAt);

        $subscription = Subscription::query()->create([
            'user_id' => $user->id,
            'plan' => $plan,
            'started_at' => $startedAt,
            'renews_at' => $renewsAt,
            'status' => $status,
            'active' => $active,
        ]);
        $limit = app(SubscriptionLimitPeriodService::class)->createInitialLimit($subscription);

        return [$subscription, $limit];
    }

    private function scenarioRenewalDate(
        SubscriptionPlan $plan,
        SubscriptionStatus $status,
        Carbon $startedAt
    ): Carbon {
        if ($status === SubscriptionStatus::Trialing) {
            return $startedAt->copy()->addDays(
                max(1, (int) config('subscriptions.trial.days', 7))
            );
        }

        return match ((string) config("subscriptions.plans.{$plan->value}.interval", 'monthly')) {
            'annual', 'annually', 'year', 'yearly' => $startedAt->copy()->addYearNoOverflow(),
            default => $startedAt->copy()->addMonthNoOverflow(),
        };
    }
}
