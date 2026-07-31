<?php

namespace Tests\Unit\Classes\Subscriptions;

use App\Classes\Subscriptions\SubscriptionPlanCapabilityService;
use App\Enums\SubscriptionPlan;
use App\Models\Profile;
use App\Models\User;
use Tests\Support\CreatesSubscriptionScenarios;
use Tests\TestCase;

class SubscriptionPlanCapabilityServiceTest extends TestCase
{
    use CreatesSubscriptionScenarios;

    public function test_it_resolves_capabilities_from_the_profiles_active_plan(): void
    {
        config([
            'subscriptions.plans.starter.capabilities.products_per_profile' => 2,
            'subscriptions.plans.starter.capabilities.integrations.instagram.selected_media' => 3,
            'products.max_products' => 99,
            'instagram.selection_limit' => 99,
        ]);
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create();
        $this->createConfiguredSubscription($user, SubscriptionPlan::Starter);
        $service = app(SubscriptionPlanCapabilityService::class);

        $this->assertSame(SubscriptionPlan::Starter, $service->planForProfile($profile));
        $this->assertSame(2, $service->productsPerProfile($profile));
        $this->assertSame(3, $service->selectedMediaPerProfile($profile, 'instagram'));
    }

    public function test_it_uses_the_default_plan_for_legacy_profiles_without_a_subscription(): void
    {
        config([
            'subscriptions.default_plan' => 'starter',
            'subscriptions.plans.starter.capabilities.products_per_profile' => 4,
        ]);
        $profile = Profile::factory()->for(User::factory())->create();
        $service = app(SubscriptionPlanCapabilityService::class);

        $this->assertSame(SubscriptionPlan::Starter, $service->planForProfile($profile));
        $this->assertSame(4, $service->productsPerProfile($profile));
    }

    public function test_zero_is_a_valid_capability_limit(): void
    {
        config([
            'subscriptions.plans.starter.capabilities.products_per_profile' => 0,
            'subscriptions.plans.starter.capabilities.integrations.tiktok.selected_media' => 0,
        ]);
        $profile = Profile::factory()->for(User::factory())->create();
        $service = app(SubscriptionPlanCapabilityService::class);

        $this->assertSame(0, $service->productsPerProfile($profile));
        $this->assertSame(0, $service->selectedMediaPerProfile($profile, 'tiktok'));
    }

    public function test_product_capacity_never_exceeds_the_application_catalog_limit(): void
    {
        config([
            'products.max_products' => 15,
            'subscriptions.plans.admin.capabilities.products_per_profile' => 2147483647,
        ]);
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create();
        $this->createConfiguredSubscription($user, SubscriptionPlan::Admin);

        $this->assertSame(15, app(SubscriptionPlanCapabilityService::class)->productsPerProfile($profile));
    }
}
