<?php

namespace App\Classes\Subscriptions;

use App\Enums\SubscriptionPlan;
use Illuminate\Support\Carbon;

class CustomerTermsAcceptance
{
    public function __construct(private readonly Carbon $acceptedAt) {}

    /**
     * @return array{customer_terms_version:string,customer_terms_accepted_at:Carbon,accepted_plan_price_usd:float}
     */
    public function paymentOrderAttributes(SubscriptionPlan $plan, SubscriptionPlanCatalog $planCatalog): array
    {
        $planConfig = $planCatalog->configFor($plan);

        return [
            'customer_terms_version' => (string) config('subscriptions.customer_terms_version', '2026-07-29'),
            'customer_terms_accepted_at' => $this->acceptedAt,
            'accepted_plan_price_usd' => round((float) ($planConfig['price_usd'] ?? 0), 2),
        ];
    }
}
