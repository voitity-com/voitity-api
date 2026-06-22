<?php

namespace App\Http\Responses\Subscription;

class SubscriptionPlansResponse
{
    /**
     * @param  array<string, mixed>  $plans
     */
    public function __construct(private readonly array $plans) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plans' => collect($this->plans)
                ->map(fn (array $plan, string $id): array => [
                    'id' => $id,
                    'name' => $plan['name'] ?? null,
                    'price_usd' => $plan['price_usd'] ?? null,
                    'currency' => $plan['currency'] ?? null,
                    'interval' => $plan['interval'] ?? null,
                    'limits' => $plan['limits'] ?? [],
                    'credits' => $plan['credits'] ?? [],
                    'purchasable' => is_numeric($plan['price_usd'] ?? null) && ((float) $plan['price_usd']) > 0,
                ])
                ->values()
                ->all(),
            'display_currency' => config('payment.display_currency', 'USD'),
            'processing_currency' => config('payment.processing_currency', 'COP'),
            'exchange_rate' => (float) config('payment.usd_cop_rate', 4000),
        ];
    }
}
