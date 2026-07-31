<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Enums\ProfileStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\CreditWallet;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\User;

class ProfileMessagingCapabilitiesControllerTest extends TestAPI
{
    public function test_api_user_can_read_enabled_capabilities_for_a_public_profile(): void
    {
        $owner = User::factory()->create();
        $profile = $this->publishedProfileFor($owner);
        $this->createSubscriptionFor($owner);
        $apiUser = User::factory()->create(['role' => 'api']);
        $token = $apiUser->createToken('public-web', ['profile:read'])->plainTextToken;

        $response = $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/messaging-capabilities");

        $response->assertOk()
            ->assertJsonPath('data.text_messages_enabled', true)
            ->assertJsonPath('data.audio_messages_enabled', true)
            ->assertJsonPath('data.audio_max_duration_seconds', 30)
            ->assertJsonPath('data.reason', null);
    }

    public function test_audio_is_disabled_without_disabling_text_when_only_audio_quota_is_exhausted(): void
    {
        $owner = User::factory()->create();
        $profile = $this->publishedProfileFor($owner);
        $subscription = $this->createSubscriptionFor($owner);
        $subscription->limit()->update(['incoming_audio_messages_remaining' => 0]);
        $token = $owner->createToken('owner', ['profile:read'])->plainTextToken;

        $response = $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/messaging-capabilities");

        $response->assertOk()
            ->assertJsonPath('data.text_messages_enabled', true)
            ->assertJsonPath('data.audio_messages_enabled', false)
            ->assertJsonPath('data.reason', 'audio_message_limit_reached');
    }

    public function test_all_messaging_is_disabled_when_chat_quota_is_exhausted(): void
    {
        $owner = User::factory()->create();
        $profile = $this->publishedProfileFor($owner);
        $subscription = $this->createSubscriptionFor($owner);
        $subscription->limit()->update(['chat_messages_remaining' => 0]);
        $token = $owner->createToken('owner', ['profile:read'])->plainTextToken;

        $response = $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/messaging-capabilities");

        $response->assertOk()
            ->assertJsonPath('data.text_messages_enabled', false)
            ->assertJsonPath('data.audio_messages_enabled', false)
            ->assertJsonPath('data.reason', 'chat_message_limit_reached');
    }

    public function test_purchased_credits_keep_paid_profile_messaging_enabled(): void
    {
        $owner = User::factory()->create();
        $profile = $this->publishedProfileFor($owner);
        $subscription = $this->createSubscriptionFor($owner);
        $subscription->limit()->update([
            'chat_messages_remaining' => 0,
            'incoming_audio_messages_remaining' => 0,
        ]);
        CreditWallet::create([
            'user_id' => $owner->id,
            'available_units' => 920,
        ]);
        $token = $owner->createToken('owner', ['profile:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/messaging-capabilities")
            ->assertOk()
            ->assertJsonPath('data.text_messages_enabled', true)
            ->assertJsonPath('data.audio_messages_enabled', true)
            ->assertJsonPath('data.audio_max_duration_seconds', 30)
            ->assertJsonPath('data.reason', null);
    }

    public function test_audio_duration_is_reduced_to_what_the_wallet_can_cover(): void
    {
        $owner = User::factory()->create();
        $profile = $this->publishedProfileFor($owner);
        $subscription = $this->createSubscriptionFor($owner);
        $subscription->limit()->update(['incoming_audio_seconds_remaining' => 0]);
        CreditWallet::create([
            'user_id' => $owner->id,
            'available_units' => 500,
        ]);
        $token = $owner->createToken('owner', ['profile:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/messaging-capabilities")
            ->assertOk()
            ->assertJsonPath('data.text_messages_enabled', true)
            ->assertJsonPath('data.audio_messages_enabled', true)
            ->assertJsonPath('data.audio_max_duration_seconds', 20);
    }

    public function test_trial_does_not_expose_messaging_from_a_purchased_credit_wallet(): void
    {
        $owner = User::factory()->create();
        $profile = $this->publishedProfileFor($owner);
        $subscription = $this->createSubscriptionFor($owner, SubscriptionStatus::Trialing);
        $subscription->limit()->update(['chat_messages_remaining' => 0]);
        CreditWallet::create([
            'user_id' => $owner->id,
            'available_units' => 1000000,
        ]);
        $token = $owner->createToken('owner', ['profile:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/messaging-capabilities")
            ->assertOk()
            ->assertJsonPath('data.text_messages_enabled', false)
            ->assertJsonPath('data.audio_messages_enabled', false)
            ->assertJsonPath('data.reason', 'chat_message_limit_reached');
    }

    private function publishedProfileFor(User $user): Profile
    {
        return Profile::factory()->for($user)->create([
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);
    }

    private function createSubscriptionFor(
        User $user,
        SubscriptionStatus $status = SubscriptionStatus::First,
    ): Subscription {
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan' => SubscriptionPlan::Starter,
            'started_at' => now()->subDay(),
            'renews_at' => now()->addMonth(),
            'status' => $status,
            'active' => true,
        ]);

        SubscriptionLimit::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'period_started_at' => $subscription->started_at,
            'period_renews_at' => $subscription->renews_at,
            'profiles_remaining' => 0,
            'avatar_images_remaining' => 1,
            'avatar_video_seconds_remaining' => 5,
            'voice_clones_remaining' => 1,
            'tts_characters_remaining' => 20000,
            'chat_messages_remaining' => 1000,
            'incoming_audio_messages_remaining' => 500,
            'incoming_audio_seconds_remaining' => 15000,
            'credits_remaining' => 1000,
        ]);

        return $subscription;
    }
}
