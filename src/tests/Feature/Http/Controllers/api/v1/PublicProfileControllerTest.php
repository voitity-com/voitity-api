<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Enums\ProfileStatus;
use App\Models\AiImage;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\User;

class PublicProfileControllerTest extends TestAPI
{
    public function test_public_seo_index_contains_only_safe_published_profile_identity(): void
    {
        $profile = $this->publicProfile([
            'alias' => 'seo-profile',
            'locale' => 'en',
            'networks' => [
                'github' => 'https://github.com/seo-profile',
                'unsafe' => 'javascript:alert(1)',
            ],
        ]);
        $hidden = $this->profile([
            'alias' => 'hidden-from-seo',
            'active' => false,
            'status' => ProfileStatus::Hidden,
        ]);
        $image = AiImage::factory()->create([
            'user_id' => $profile->user_id,
            'profile_id' => $profile->id,
            'file' => 'https://assets.example.com/seo-profile.png',
        ]);
        ProfileAvatar::factory()->create([
            'user_id' => $profile->user_id,
            'profile_id' => $profile->id,
            'aiimage_id' => $image->id,
            'file' => 'https://assets.example.com/seo-profile.mp4',
            'status' => ProfileAvatar::STATUS_ACTIVE,
        ]);

        $this->getJson('/api/public/seo/profiles')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=300, public')
            ->assertJsonCount(1, 'data.profiles')
            ->assertJsonPath('data.profiles.0.alias', 'seo-profile')
            ->assertJsonPath('data.profiles.0.name', $profile->name)
            ->assertJsonPath('data.profiles.0.locale', 'en')
            ->assertJsonPath('data.profiles.0.image_url', 'https://assets.example.com/seo-profile.png')
            ->assertJsonPath('data.profiles.0.networks.github', 'https://github.com/seo-profile')
            ->assertJsonMissingPath('data.profiles.0.networks.unsafe')
            ->assertJsonMissing(['alias' => $hidden->alias])
            ->assertJsonMissingPath('data.profiles.0.id')
            ->assertJsonMissingPath('data.profiles.0.description')
            ->assertJsonMissingPath('data.profiles.0.data');
    }

    public function test_published_active_profile_is_available_without_authentication(): void
    {
        $profile = $this->publicProfile([
            'alias' => 'public-profile',
            'networks' => [
                'instagram' => 'https://instagram.com/public-profile',
            ],
        ]);

        $response = $this->getJson('/api/public/profiles/public-profile');

        $response->assertOk()
            ->assertJsonPath('message', 'Profile retrieved successfully.')
            ->assertJsonPath('data.id', $profile->id)
            ->assertJsonPath('data.alias', 'public-profile')
            ->assertJsonPath('data.name', $profile->name)
            ->assertJsonPath(
                'data.networks.instagram',
                'https://instagram.com/public-profile',
            )
            ->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.voice_id')
            ->assertJsonMissingPath('data.publication')
            ->assertJsonMissingPath('data.created_at')
            ->assertJsonMissingPath('data.updated_at');
    }

    public function test_draft_hidden_and_inactive_profiles_are_not_public(): void
    {
        $draft = $this->profile([
            'alias' => 'draft-profile',
            'active' => true,
            'status' => ProfileStatus::Draft,
        ]);
        $hidden = $this->profile([
            'alias' => 'hidden-profile',
            'active' => true,
            'status' => ProfileStatus::Hidden,
        ]);
        $inactive = $this->profile([
            'alias' => 'inactive-profile',
            'active' => false,
            'status' => ProfileStatus::Published,
        ]);

        foreach ([$draft, $hidden, $inactive] as $profile) {
            $this->getJson("/api/public/profiles/{$profile->alias}")
                ->assertNotFound()
                ->assertJsonPath('message', 'Profile not found.');
        }
    }

    public function test_public_social_network_definitions_do_not_require_authentication(): void
    {
        config([
            'social-networks.networks.instagram' => [
                'name' => 'Instagram',
                'icon' => 'https://assets.example.com/instagram.png',
            ],
        ]);

        $this->getJson('/api/public/social-networks')
            ->assertOk()
            ->assertJsonPath('data.networks.instagram.name', 'Instagram')
            ->assertJsonPath(
                'data.networks.instagram.icon',
                'https://assets.example.com/instagram.png',
            );
    }

    public function test_only_active_avatar_file_is_exposed_for_public_profile(): void
    {
        $profile = $this->publicProfile();
        ProfileAvatar::factory()->create([
            'user_id' => $profile->user_id,
            'profile_id' => $profile->id,
            'file' => 'aivideos/public-avatar.mp4',
            'status' => ProfileAvatar::STATUS_ACTIVE,
        ]);

        $this->getJson("/api/public/profiles/{$profile->id}/avatar")
            ->assertOk()
            ->assertJsonPath('data.file', 'aivideos/public-avatar.mp4')
            ->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.profile_id')
            ->assertJsonMissingPath('data.failure_reason');
    }

    public function test_avatar_and_capabilities_are_hidden_with_non_public_profile(): void
    {
        $profile = $this->profile([
            'active' => false,
            'status' => ProfileStatus::Published,
        ]);
        ProfileAvatar::factory()->create([
            'user_id' => $profile->user_id,
            'profile_id' => $profile->id,
            'file' => 'aivideos/private-avatar.mp4',
            'status' => ProfileAvatar::STATUS_ACTIVE,
        ]);

        $this->getJson("/api/public/profiles/{$profile->id}/avatar")
            ->assertNotFound();
        $this->getJson("/api/public/profiles/{$profile->id}/messaging-capabilities")
            ->assertNotFound();
    }

    public function test_public_profile_capabilities_do_not_require_authentication(): void
    {
        $profile = $this->publicProfile();

        $this->getJson("/api/public/profiles/{$profile->id}/messaging-capabilities")
            ->assertOk()
            ->assertJsonPath('data.text_messages_enabled', false)
            ->assertJsonPath('data.audio_messages_enabled', false)
            ->assertJsonPath('data.reason', 'subscription_inactive');
    }

    private function publicProfile(array $attributes = []): Profile
    {
        return $this->profile([
            'active' => true,
            'status' => ProfileStatus::Published,
            ...$attributes,
        ]);
    }

    private function profile(array $attributes = []): Profile
    {
        return Profile::factory()
            ->for(User::factory())
            ->create($attributes);
    }
}
