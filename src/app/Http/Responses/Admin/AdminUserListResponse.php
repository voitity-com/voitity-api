<?php

namespace App\Http\Responses\Admin;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class AdminUserListResponse
{
    public function __construct(private readonly LengthAwarePaginator $users) {}

    public function toArray(): array
    {
        return [
            'users' => $this->users->getCollection()
                ->map(fn (User $user) => (new AdminUserResponse($user))->toArray())
                ->values()
                ->all(),
            'subscription_plans' => $this->subscriptionPlans(),
            'pagination' => [
                'current_page' => $this->users->currentPage(),
                'per_page' => $this->users->perPage(),
                'last_page' => $this->users->lastPage(),
                'total' => $this->users->total(),
            ],
        ];
    }

    private function subscriptionPlans(): array
    {
        return collect(config('subscriptions.plans', []))
            ->filter(fn (array $plan): bool => ($plan['assignable'] ?? true) !== false)
            ->map(fn (array $plan, string $id): array => [
                'id' => $id,
                'name' => $plan['name'] ?? $id,
                'price_usd' => $plan['price_usd'] ?? null,
                'currency' => $plan['currency'] ?? 'USD',
                'interval' => $plan['interval'] ?? 'monthly',
                'unlimited' => (bool) ($plan['unlimited'] ?? false),
            ])
            ->values()
            ->all();
    }
}
