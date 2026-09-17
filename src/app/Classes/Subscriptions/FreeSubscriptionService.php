<?php

namespace App\Classes\Subscriptions;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;

class FreeSubscriptionService
{
    public function __construct(
        private readonly SubscriptionPlanAssigner $assigner,
        private readonly SubscriptionProfileAccessService $profileAccess,
        private readonly FreePlanDataReconciler $freePlanData,
    ) {}

    public function ensureFor(User|int $user): Subscription
    {
        $user = $user instanceof User ? $user : User::query()->findOrFail($user);
        $subscription = $user->subscriptions()
            ->where('active', true)
            ->latest('started_at')
            ->first();

        if ($subscription instanceof Subscription) {
            if ($subscription->renews_at->isFuture() || $this->hasPaymentRecoveryWindow($subscription)) {
                if ($subscription->plan === SubscriptionPlan::Free) {
                    $this->freePlanData->apply($subscription);
                }

                return $subscription;
            }

            if ($subscription->plan === SubscriptionPlan::Free || $subscription->plan === SubscriptionPlan::Admin) {
                return $this->assigner->assign($user, $subscription->plan, [
                    'billing_mode' => $subscription->plan === SubscriptionPlan::Admin
                        ? 'admin_grant'
                        : 'free_recurring',
                    'last_billed_at' => null,
                ]);
            }
        }

        $recoverable = $user->subscriptions()
            ->where('status', SubscriptionStatus::PastDue->value)
            ->whereNotNull('payment_failure_code')
            ->latest('started_at')
            ->first();

        if ($recoverable instanceof Subscription && $this->hasPaymentRecoveryWindow($recoverable)) {
            $recoverable->active = true;
            $recoverable->save();
            $this->profileAccess->restoreProfilesAfterPaymentRecovery($recoverable, $recoverable->id);

            return $recoverable->fresh();
        }

        return $this->assignDefaultPlan($user);
    }

    public function downgrade(User|int $user): Subscription
    {
        $user = $user instanceof User ? $user : User::query()->findOrFail($user);

        if ($user->role === 'admin') {
            return $this->ensureFor($user);
        }

        $current = $user->subscriptions()
            ->where('active', true)
            ->where('plan', SubscriptionPlan::Free->value)
            ->where('renews_at', '>', now())
            ->latest('started_at')
            ->first();

        if ($current instanceof Subscription) {
            $this->freePlanData->apply($current);

            return $current;
        }

        return $this->assigner->assign($user, SubscriptionPlan::Free, [
            'billing_mode' => 'free_recurring',
            'last_billed_at' => null,
            'payment_failure_code' => null,
            'payment_failed_at' => null,
            'payment_retry_count' => 0,
            'next_payment_retry_at' => null,
            'last_failed_payment_order_id' => null,
            'access_ended_reason' => null,
        ]);
    }

    public function hasPaymentRecoveryWindow(Subscription $subscription): bool
    {
        return $subscription->status === SubscriptionStatus::PastDue
            && filled($subscription->payment_failure_code)
            && $subscription->next_payment_retry_at !== null
            && $subscription->payment_retry_count < count(config('subscriptions.payment_retry_hours', [6, 24, 72])) + 1;
    }

    private function assignDefaultPlan(User $user): Subscription
    {
        $plan = $user->role === 'admin' ? SubscriptionPlan::Admin : SubscriptionPlan::Free;

        return $this->assigner->assign($user, $plan, [
            'billing_mode' => $plan === SubscriptionPlan::Admin ? 'admin_grant' : 'free_recurring',
            'last_billed_at' => null,
        ]);
    }
}
