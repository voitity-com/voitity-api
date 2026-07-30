<?php

namespace Tests\Feature\Http\Controllers\api\v1;

class PublicSubscriptionPlansControllerTest extends TestAPI
{
    public function test_public_plans_expose_current_prices_limits_and_capabilities_without_authentication(): void
    {
        $response = $this->getJson('/api/subscription/public-plans');

        $response->assertOk()
            ->assertJsonPath('data.plans.0.id', 'starter')
            ->assertJsonPath('data.plans.0.price_usd', 12.99)
            ->assertJsonPath('data.plans.0.limits.tts_characters', 20000)
            ->assertJsonPath('data.plans.0.limits.chat_messages', 1000)
            ->assertJsonPath('data.plans.0.limits.incoming_audio_messages', 500)
            ->assertJsonPath('data.plans.0.limits.incoming_audio_seconds', 15000)
            ->assertJsonPath('data.plans.0.capabilities.products_per_profile', 15)
            ->assertJsonPath('data.plans.0.capabilities.integrations.instagram.selected_media', 10)
            ->assertJsonPath('data.plans.1.id', 'starter_annual')
            ->assertJsonPath('data.plans.1.price_usd', 129);
    }
}
