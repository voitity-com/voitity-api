<?php

namespace App\Classes\Subscriptions;

use App\Enums\SubscriptionPlan;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

class SubscriptionRenewalService
{
    public function __construct(
        private readonly SubscriptionPlanAssigner $subscriptionPlanAssigner,
        private readonly SubscriptionPlanCatalog $planCatalog
    ) {}

    public function renewIfFree(Subscription $subscription): Subscription
    {
        if ($subscription->renews_at->isFuture()) {
            return $subscription;
        }

        if (! $this->planCatalog->isFreeRenewing($subscription->plan)) {
            return $subscription;
        }

        return $this->subscriptionPlanAssigner->assign(
            $subscription->user,
            $subscription->plan,
            $this->attributesFor($subscription->plan)
        );
    }

    public function renewDueFreeSubscriptions(): int
    {
        $count = 0;

        Subscription::query()
            ->where('active', true)
            ->where('renews_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use (&$count): void {
                foreach ($subscriptions as $subscription) {
                    if (! $subscription instanceof Subscription) {
                        continue;
                    }

                    if (! $this->planCatalog->isFreeRenewing($subscription->plan)) {
                        continue;
                    }

                    DB::transaction(function () use ($subscription, &$count): void {
                        $lockedSubscription = Subscription::whereKey($subscription->id)
                            ->lockForUpdate()
                            ->first();

                        if (! $lockedSubscription instanceof Subscription || ! $lockedSubscription->active) {
                            return;
                        }

                        if ($lockedSubscription->renews_at->isFuture()) {
                            return;
                        }

                        $this->renewIfFree($lockedSubscription);
                        $count++;
                    });
                }
            });

        return $count;
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFor(SubscriptionPlan $plan): array
    {
        return [
            'billing_mode' => $plan === SubscriptionPlan::Admin ? 'admin_grant' : 'free_recurring',
            'last_billed_at' => null,
        ];
    }
}
