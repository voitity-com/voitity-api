<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Enums\ProfileStatus;
use App\Models\AiImage;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\ProfileWidget;
use App\Models\User;

class ProfileWidgetControllerTest extends TestAPI
{
    public function test_owner_can_read_and_update_widget_settings(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create();
        $token = $user->createToken('widget', ['profile:read', 'profile:write'])->plainTextToken;

        $response = $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/widget")
            ->assertOk()
            ->assertJsonPath('data.widget.enabled', false)
            ->assertJsonPath('data.widget.available', false)
            ->assertJsonPath('data.widget.profile_active', false)
            ->assertJsonPath('data.widget.profile_status', ProfileStatus::Draft->value);

        $publicKey = $response->json('data.widget.public_key');
        $this->assertIsString($publicKey);
        $this->assertDatabaseHas('profile_widgets', [
            'profile_id' => $profile->id,
            'public_key' => $publicKey,
            'enabled' => false,
        ]);

        $this->withToken($token)
            ->patchJson("/api/profile/{$profile->id}/widget", ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.widget.enabled', true)
            ->assertJsonPath('data.widget.public_key', $publicKey);

        $this->assertDatabaseHas('profile_widgets', [
            'profile_id' => $profile->id,
            'enabled' => true,
        ]);
    }

    public function test_widget_endpoints_enforce_abilities_validation_and_ownership(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $profile = Profile::factory()->for($owner)->create();
        $readOnlyToken = $owner->createToken('read-only', ['profile:read'])->plainTextToken;
        $otherToken = $other->createToken('other', ['profile:read', 'profile:write'])->plainTextToken;

        $this->withToken($readOnlyToken)
            ->patchJson("/api/profile/{$profile->id}/widget", ['enabled' => true])
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $writeToken = $owner->createToken('write', ['profile:write'])->plainTextToken;
        $this->withToken($writeToken)
            ->getJson("/api/profile/{$profile->id}/widget")
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $ownerToken = $owner->createToken('owner', ['profile:read', 'profile:write'])->plainTextToken;
        $this->withToken($ownerToken)
            ->patchJson("/api/profile/{$profile->id}/widget", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['enabled']);

        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)
            ->getJson("/api/profile/{$profile->id}/widget")
            ->assertNotFound();

        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)
            ->patchJson("/api/profile/{$profile->id}/widget", ['enabled' => true])
            ->assertNotFound();
    }

    public function test_admin_can_manage_another_users_widget(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($owner)->create();
        $token = $admin->createToken('admin-widget', ['profile:read', 'profile:write'])->plainTextToken;

        $this->withToken($token)
            ->patchJson("/api/profile/{$profile->id}/widget", ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('data.widget.enabled', true);
    }

    public function test_public_widget_returns_only_safe_configuration_when_available(): void
    {
        $profile = Profile::factory()->for(User::factory())->create([
            'active' => true,
            'status' => ProfileStatus::Published,
            'locale' => 'en',
            'alias' => 'widget-profile',
            'name' => 'Widget Profile',
        ]);
        $image = AiImage::factory()->create([
            'user_id' => $profile->user_id,
            'profile_id' => $profile->id,
            'file' => 'https://assets.example.com/widget-avatar.png',
            'status' => 'succeeded',
        ]);
        ProfileAvatar::factory()->create([
            'user_id' => $profile->user_id,
            'profile_id' => $profile->id,
            'aiimage_id' => $image->id,
            'file' => 'https://assets.example.com/widget-avatar.mp4',
            'status' => ProfileAvatar::STATUS_ACTIVE,
        ]);
        $widget = ProfileWidget::query()->create([
            'profile_id' => $profile->id,
            'enabled' => true,
        ]);

        $this->getJson("/api/public/widgets/{$widget->public_key}")
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.widget.public_key', $widget->public_key)
            ->assertJsonPath('data.widget.profile.id', $profile->id)
            ->assertJsonPath('data.widget.profile.alias', 'widget-profile')
            ->assertJsonPath('data.widget.profile.name', 'Widget Profile')
            ->assertJsonPath('data.widget.profile.locale', 'en')
            ->assertJsonPath('data.widget.launcher.label', 'Talk to me')
            ->assertJsonPath('data.widget.launcher.avatar_url', 'https://assets.example.com/widget-avatar.png')
            ->assertJsonMissingPath('data.widget.enabled')
            ->assertJsonMissingPath('data.widget.profile.user_id')
            ->assertJsonMissingPath('data.widget.profile.description')
            ->assertJsonMissingPath('data.widget.profile.data');
    }

    public function test_public_widget_hides_disabled_and_non_public_profiles(): void
    {
        $profile = Profile::factory()->for(User::factory())->create([
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);
        $widget = ProfileWidget::query()->create([
            'profile_id' => $profile->id,
            'enabled' => false,
        ]);

        $this->getJson("/api/public/widgets/{$widget->public_key}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Widget not found.');

        $widget->update(['enabled' => true]);
        $profile->update(['active' => false]);

        $this->getJson("/api/public/widgets/{$widget->public_key}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Widget not found.');

        $this->getJson('/api/public/widgets/not-a-real-key')
            ->assertNotFound()
            ->assertJsonPath('message', 'Widget not found.');
    }
}
