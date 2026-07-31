<?php

namespace App\Classes\Subscriptions;

class SubscriptionUsageAllocation
{
    /**
     * @param  array<string, int>  $planCovered
     * @param  array<string, int>  $creditCovered
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        public readonly array $planCovered,
        public readonly array $creditCovered,
        public readonly int $creditUnits,
        public readonly array $errors = [],
    ) {}
}
