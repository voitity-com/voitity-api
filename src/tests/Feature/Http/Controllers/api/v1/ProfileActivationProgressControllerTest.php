<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Enums\ActivationEventType;
use App\Enums\ProfileStatus;
use App\Models\Profile;
use App\Models\User;

class ProfileActivationProgressControllerTest extends TestAPI
{
    public function test_owner_can_read_progress_and_record_copied_link_once(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create([
            'active' => true,
            'status' => ProfileStatus::Published,
            'networks' => ['whatsapp' => 'https://wa.me/573001234567'],
        ]);
        $token = $user->createToken('activation', ['profile:read', 'profile:write'])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/activation-progress")
            ->assertOk()
            ->assertJsonPath('data.published', true)
            ->assertJsonPath('data.whatsapp_added', true)
            ->assertJsonPath('data.product_created', false)
            ->assertJsonPath('data.conversation_started', false)
            ->assertJsonPath('data.link_copied', false);

        $this->withToken($token)
            ->postJson("/api/profile/{$profile->id}/activation-events", ['event_type' => 'link_copied'])
            ->assertCreated()
            ->assertJsonPath('data.event_type', ActivationEventType::LinkCopied->value);

        $this->withToken($token)
            ->postJson("/api/profile/{$profile->id}/activation-events", ['event_type' => 'link_copied'])
            ->assertOk();

        $this->assertDatabaseCount('activation_events', 1);
        $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/activation-progress")
            ->assertJsonPath('data.link_copied', true);
    }

    public function test_non_owner_can_not_read_or_write_progress(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $profile = Profile::factory()->for($owner)->create();
        $token = $other->createToken('activation', ['profile:read', 'profile:write'])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/activation-progress")
            ->assertNotFound();
        $this->withToken($token)
            ->postJson("/api/profile/{$profile->id}/activation-events", ['event_type' => 'link_copied'])
            ->assertNotFound();
    }

    public function test_missing_token_ability_is_rejected(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create();
        $token = $user->createToken('activation', ['user:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/activation-progress")
            ->assertForbidden();
        $this->withToken($token)
            ->postJson("/api/profile/{$profile->id}/activation-events", ['event_type' => 'link_copied'])
            ->assertForbidden();
    }
}
