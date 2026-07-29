<?php

namespace App\Classes\Subscriptions;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionPlanAssigner
{
    public function __construct(
        private readonly ?SubscriptionLimitPeriodService $limitPeriods = null,
        private readonly ?SubscriptionProfileAccessService $profileAccess = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function assign(User $user, SubscriptionPlan $plan, array $attributes = []): Subscription
    {
        return DB::transaction(function () use ($user, $plan, $attributes): Subscription {
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
            $renewsAt = $this->renewsAt($plan, $startedAt);

            /** @var Subscription $subscription */
            $subscription = $lockedUser->subscriptions()->create(array_merge([
                'plan' => $plan,
                'started_at' => $startedAt,
                'renews_at' => $renewsAt,
                'status' => $previousSubscription ? SubscriptionStatus::Renewed : SubscriptionStatus::First,
                'active' => true,
                'billing_mode' => 'recurring',
                'next_billing_at' => $renewsAt,
            ], $attributes));

            $this->limitPeriods()->createInitialLimit($subscription);
            $this->profileAccess()->enforceActiveProfileLimit($subscription);

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

    private function limitPeriods(): SubscriptionLimitPeriodService
    {
        return $this->limitPeriods ?? app(SubscriptionLimitPeriodService::class);
    }

    private function profileAccess(): SubscriptionProfileAccessService
    {
        return $this->profileAccess ?? app(SubscriptionProfileAccessService::class);
    }
}
