<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Enums\ProfileStatus;
use App\Models\AiImage;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\User;

class ProfilePreviewControllerTest extends TestAPI
{
    public function test_owner_can_preview_a_draft_profile_without_making_it_public(): void
    {
        config([
            'social-networks.networks.instagram' => [
                'name' => 'Instagram',
                'icon' => 'https://assets.example.com/instagram.png',
            ],
        ]);

        $owner = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($owner)->create([
            'active' => false,
            'alias' => 'private-preview',
            'networks' => ['instagram' => 'https://instagram.com/private-preview'],
            'status' => ProfileStatus::Draft,
        ]);
        $image = AiImage::factory()->create([
            'user_id' => $owner->id,
            'profile_id' => $profile->id,
            'file' => 'https://assets.example.com/private-preview.png',
        ]);
        ProfileAvatar::factory()->create([
            'user_id' => $owner->id,
            'profile_id' => $profile->id,
            'aiimage_id' => $image->id,
            'file' => 'aivideos/private-preview.mp4',
            'status' => ProfileAvatar::STATUS_ACTIVE,
        ]);
        $token = $owner->createToken('preview', ['profile:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/preview")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.profile.id', $profile->id)
            ->assertJsonPath('data.profile.alias', 'private-preview')
            ->assertJsonPath('data.profile.networks.instagram', 'https://instagram.com/private-preview')
            ->assertJsonPath('data.profile.messaging_capabilities.text_messages_enabled', false)
            ->assertJsonPath('data.profile.messaging_capabilities.audio_messages_enabled', false)
            ->assertJsonPath('data.avatar.file', 'aivideos/private-preview.mp4')
            ->assertJsonPath('data.avatar.image_url', 'https://assets.example.com/private-preview.png')
            ->assertJsonPath('data.social_networks.instagram.name', 'Instagram')
            ->assertJsonPath('data.preview.interactive', false)
            ->assertJsonPath('data.preview.is_published', false)
            ->assertJsonMissingPath('data.profile.user_id');

        $this->getJson('/api/public/profiles/private-preview?admin_preview=1')
            ->assertNotFound();
    }

    public function test_preview_requires_authentication_read_ability_and_profile_access(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $other = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($owner)->create();

        $this->getJson("/api/profile/{$profile->id}/preview")
            ->assertUnauthorized();

        $writeOnlyToken = $owner->createToken('write-only', ['profile:write'])->plainTextToken;
        $this->withToken($writeOnlyToken)
            ->getJson("/api/profile/{$profile->id}/preview")
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $otherToken = $other->createToken('other-preview', ['profile:read'])->plainTextToken;
        $this->withToken($otherToken)
            ->getJson("/api/profile/{$profile->id}/preview")
            ->assertNotFound();
    }

    public function test_admin_can_preview_another_users_published_profile(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $admin = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($owner)->create([
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);
        $token = $admin->createToken('admin-preview', ['profile:read'])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/preview")
            ->assertOk()
            ->assertJsonPath('data.profile.id', $profile->id)
            ->assertJsonPath('data.preview.interactive', true)
            ->assertJsonPath('data.preview.is_published', true);
    }
}
