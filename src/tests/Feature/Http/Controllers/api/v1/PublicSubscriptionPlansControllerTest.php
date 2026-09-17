<?php

namespace Tests\Feature\Http\Controllers\api\v1;

class PublicSubscriptionPlansControllerTest extends TestAPI
{
    public function test_public_plans_expose_current_prices_limits_and_capabilities_without_authentication(): void
    {
        $response = $this->getJson('/api/subscription/public-plans');

        $response->assertOk()
            ->assertJsonPath('data.plans.0.id', 'free')
            ->assertJsonPath('data.plans.0.price_usd', 0)
            ->assertJsonPath('data.plans.0.purchasable', false)
            ->assertJsonPath('data.plans.0.limits.chat_messages', 100)
            ->assertJsonPath('data.plans.0.limits.incoming_audio_messages', 0)
            ->assertJsonPath('data.plans.0.capabilities.avatar_upload', true)
            ->assertJsonPath('data.plans.0.capabilities.ai_avatar', false)
            ->assertJsonPath('data.plans.0.capabilities.voice_clone', false)
            ->assertJsonPath('data.plans.0.capabilities.products_per_profile', 1)
            ->assertJsonPath('data.plans.0.capabilities.integrations_per_profile', 0)
            ->assertJsonPath('data.plans.0.capabilities.integrations.instagram.selected_media', 0)
            ->assertJsonPath('data.plans.0.capabilities.profile_templates.0', 'profile01')
            ->assertJsonPath('data.plans.1.id', 'starter')
            ->assertJsonPath('data.plans.1.price_usd', 12.99)
            ->assertJsonPath('data.plans.1.limits.tts_characters', 20000)
            ->assertJsonPath('data.plans.1.capabilities.products_per_profile', 15)
            ->assertJsonPath('data.plans.2.id', 'starter_annual')
            ->assertJsonPath('data.plans.2.price_usd', 129);
    }
}
