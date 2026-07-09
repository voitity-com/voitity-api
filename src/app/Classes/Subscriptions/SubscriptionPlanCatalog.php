<?php

namespace App\Classes\Subscriptions;

use App\Enums\SubscriptionPlan;
use Illuminate\Support\Collection;

class SubscriptionPlanCatalog
{
    /**
     * @return array<string, mixed>
     */
    public function configFor(SubscriptionPlan $plan): array
    {
        $config = config("subscriptions.plans.{$plan->value}", []);

        return is_array($config) ? $config : [];
    }

    public function isActive(SubscriptionPlan $plan): bool
    {
        return ($this->configFor($plan)['active'] ?? false) === true;
    }

    public function isAssignable(SubscriptionPlan $plan): bool
    {
        $config = $this->configFor($plan);

        return ($config['active'] ?? false) === true
            && ($config['assignable'] ?? true) !== false;
    }

    public function isPurchasable(SubscriptionPlan $plan): bool
    {
        $config = $this->configFor($plan);
        $priceUsd = $config['price_usd'] ?? null;

        return ($config['active'] ?? false) === true
            && ($config['purchasable'] ?? false) === true
            && is_numeric($priceUsd)
            && (float) $priceUsd > 0;
    }

    public function isVisible(SubscriptionPlan $plan): bool
    {
        $config = $this->configFor($plan);

        return ($config['active'] ?? false) === true
            && ($config['visible'] ?? true) !== false;
    }

    public function isFreeRenewing(SubscriptionPlan $plan): bool
    {
        $config = $this->configFor($plan);
        $priceUsd = $config['price_usd'] ?? null;

        return ($config['active'] ?? false) === true
            && is_numeric($priceUsd)
            && (float) $priceUsd <= 0;
    }

    public function isUnlimited(SubscriptionPlan $plan): bool
    {
        return (bool) ($this->configFor($plan)['unlimited'] ?? false);
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    public function publicPlans(): Collection
    {
        return collect(config('subscriptions.plans', []))
            ->filter(function (array $config, string $id): bool {
                $plan = SubscriptionPlan::tryFrom($id);

                return $plan instanceof SubscriptionPlan
                    && $this->isActive($plan)
                    && $this->isVisible($plan);
            });
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    public function assignablePlans(): Collection
    {
        return collect(config('subscriptions.plans', []))
            ->filter(function (array $config, string $id): bool {
                $plan = SubscriptionPlan::tryFrom($id);

                return $plan instanceof SubscriptionPlan && $this->isAssignable($plan);
            });
    }
}
