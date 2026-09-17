<?php

namespace App\Classes\Subscriptions;

use App\Enums\SubscriptionPlan;
use App\Exceptions\Subscriptions\SubscriptionEntitlementException;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;

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

        $profile->loadMissing('user');

        if ($profile->user?->role === 'admin') {
            return SubscriptionPlan::Admin;
        }

        return SubscriptionPlan::tryFrom((string) config('subscriptions.default_plan'))
            ?? SubscriptionPlan::Free;
    }

    public function planForUser(User|int $user): SubscriptionPlan
    {
        $userId = $user instanceof User ? $user->id : $user;
        $subscription = Subscription::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->latest('started_at')
            ->first();

        if ($subscription instanceof Subscription) {
            return $subscription->plan;
        }

        $resolvedUser = $user instanceof User ? $user : User::query()->find($userId);

        if ($resolvedUser?->role === 'admin') {
            return SubscriptionPlan::Admin;
        }

        return SubscriptionPlan::tryFrom((string) config('subscriptions.default_plan'))
            ?? SubscriptionPlan::Free;
    }

    public function isFree(Profile $profile): bool
    {
        return $this->planForProfile($profile) === SubscriptionPlan::Free;
    }

    public function supports(Profile $profile, string $path, bool $fallback = true): bool
    {
        $plan = $this->planForProfile($profile);

        return (bool) data_get($this->plans->configFor($plan), "capabilities.{$path}", $fallback);
    }

    public function assertSupports(
        Profile $profile,
        string $path,
        string $featureName,
        bool $fallback = true,
    ): void {
        if ($this->supports($profile, $path, $fallback)) {
            return;
        }

        throw new SubscriptionEntitlementException(
            "{$featureName} is not included in the current plan.",
            ['plan_feature' => ["{$featureName} is not included in the current plan."]],
            403,
        );
    }

    public function productsPerProfile(Profile $profile): int
    {
        $planLimit = $this->integerCapability(
            $profile,
            'products_per_profile',
            (int) config('products.max_products', 15)
        );
        $applicationLimit = max(0, (int) config('products.max_products', 15));

        return min($planLimit, $applicationLimit);
    }

    public function selectedMediaPerProfile(Profile $profile, string $provider): int
    {
        return $this->integerCapability(
            $profile,
            "integrations.{$provider}.selected_media",
            (int) config("{$provider}.selection_limit", 10)
        );
    }

    public function integrationsPerProfile(Profile $profile): int
    {
        return $this->integerCapability($profile, 'integrations_per_profile', PHP_INT_MAX);
    }

    /**
     * @return list<string>
     */
    public function profileTemplates(Profile $profile): array
    {
        $plan = $this->planForProfile($profile);
        $configured = data_get($this->plans->configFor($plan), 'capabilities.profile_templates');

        if (! is_array($configured)) {
            return array_values(array_keys(config('profile-appearance.templates', [])));
        }

        return array_values(array_filter(
            $configured,
            fn (mixed $template): bool => is_string($template) && $template !== '',
        ));
    }

    public function includesProfileTemplate(Profile $profile, string $templateKey): bool
    {
        return in_array($templateKey, $this->profileTemplates($profile), true);
    }

    public function sourcesPerProfile(Profile $profile): int
    {
        return $this->integerCapability($profile, 'sources_per_profile', PHP_INT_MAX);
    }

    public function sourceMaxFileKilobytes(Profile $profile): int
    {
        return $this->integerCapability($profile, 'source_max_file_kb', 10240);
    }

    public function sourceMaxCharacters(Profile $profile): int
    {
        return $this->integerCapability($profile, 'source_max_characters', 50000);
    }

    public function chatMaxOutputTokens(Profile $profile): int
    {
        return max(1, $this->integerCapability($profile, 'chat_max_output_tokens', 1000));
    }

    public function chatContextTokens(Profile $profile): int
    {
        return max(1, $this->integerCapability(
            $profile,
            'chat_context_tokens',
            (int) config('ai-knowledge.retrieval.max_context_tokens', 2500)
        ));
    }

    public function analyticsDays(Profile $profile): int
    {
        return max(1, $this->integerCapability(
            $profile,
            'analytics_days',
            max(1, (int) config('insights.max_range_months', 24)) * 31,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilitiesForPlan(SubscriptionPlan $plan): array
    {
        $capabilities = $this->plans->configFor($plan)['capabilities'] ?? [];

        return is_array($capabilities) ? $capabilities : [];
    }

    private function integerCapability(Profile $profile, string $path, int $fallback): int
    {
        $plan = $this->planForProfile($profile);
        $value = data_get($this->plans->configFor($plan), "capabilities.{$path}", $fallback);

        return max(0, (int) $value);
    }
}
