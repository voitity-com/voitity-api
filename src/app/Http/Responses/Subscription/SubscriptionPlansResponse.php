<?php

namespace App\Http\Responses\Subscription;

use App\Classes\Subscriptions\SubscriptionTrialService;
use App\Models\User;

class SubscriptionPlansResponse
{
    /**
     * @param  array<string, mixed>  $plans
     */
    public function __construct(
        private readonly array $plans,
        private readonly ?User $user = null,
        private readonly ?SubscriptionTrialService $trialService = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plans' => collect($this->plans)
                ->filter(fn (array $plan): bool => ($plan['active'] ?? false) === true && ($plan['visible'] ?? true) !== false)
                ->map(fn (array $plan, string $id): array => [
                    'id' => $id,
                    'name' => $plan['name'] ?? null,
                    'price_usd' => $plan['price_usd'] ?? null,
                    'currency' => $plan['currency'] ?? null,
                    'interval' => $plan['interval'] ?? null,
                    'limits' => $plan['limits'] ?? [],
                    'credits' => $plan['credits'] ?? [],
                    'purchasable' => (bool) ($plan['purchasable'] ?? (is_numeric($plan['price_usd'] ?? null) && ((float) $plan['price_usd']) > 0)),
                    'unlimited' => (bool) ($plan['unlimited'] ?? false),
                ])
                ->values()
                ->all(),
            'display_currency' => config('payment.display_currency', 'USD'),
            'processing_currency' => config('payment.processing_currency', 'COP'),
            'exchange_rate' => (float) config('payment.usd_cop_rate', 4000),
            'trial' => $this->trialData(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function trialData(): array
    {
        $setupAmountUsd = max(0, round((float) config('subscriptions.trial.setup_amount_usd', 0), 2));
        $exchangeRate = (float) config('payment.usd_cop_rate', 4000);
        $setupAmountInCents = (int) round($setupAmountUsd * $exchangeRate * 100);

        return [
            'enabled' => (bool) config('subscriptions.trial.enabled', true),
            'available' => $this->user instanceof User && $this->trialService instanceof SubscriptionTrialService
                ? $this->trialService->userCanStartTrial($this->user)
                : false,
            'days' => max(1, (int) config('subscriptions.trial.days', 7)),
            'setup_amount_usd' => $setupAmountUsd,
            'setup_amount_cop' => round($setupAmountInCents / 100, 2),
            'setup_amount_in_cents' => $setupAmountInCents,
        ];
    }
}
