<?php

namespace App\Classes\Subscriptions;

use App\Enums\SubscriptionPlan;
use App\Models\Profile;
use App\Models\Subscription;

class SubscriptionPlanCapabilityService
{
    public function __construct(private readonly SubscriptionPlanCatalog $plans) {}

    public function planForProfile(Profile $profile): SubscriptionPlan
    {
        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()
            ->where('user_id', $profile->user_id)
            ->where('active', true)
            ->latest('started_at')
            ->first();

        if ($subscription instanceof Subscription) {
            return $subscription->plan;
        }

        return SubscriptionPlan::tryFrom((string) config('subscriptions.default_plan'))
            ?? SubscriptionPlan::Starter;
    }

    public function productsPerProfile(Profile $profile): int
    {
        return $this->integerCapability(
            $profile,
            'products_per_profile',
            (int) config('products.max_products', 15)
        );
    }

    public function selectedMediaPerProfile(Profile $profile, string $provider): int
    {
        return $this->integerCapability(
            $profile,
            "integrations.{$provider}.selected_media",
            (int) config("{$provider}.selection_limit", 10)
        );
    }

    private function integerCapability(Profile $profile, string $path, int $fallback): int
    {
        $plan = $this->planForProfile($profile);
        $value = data_get($this->plans->configFor($plan), "capabilities.{$path}", $fallback);

        return max(0, (int) $value);
    }
}
