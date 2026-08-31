<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Enums\ProfileStatus;
use App\Models\Profile;
use App\Models\ProfileAppearance;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class ProfileAppearanceControllerTest extends TestAPI
{
    public function test_owner_can_read_and_update_profile_appearance(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create();
        $token = $user->createToken('appearance', ['profile:read', 'profile:write'])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/appearance")
            ->assertOk()
            ->assertJsonPath('data.appearance.template_key', 'profile01')
            ->assertJsonPath('data.appearance.background_type', ProfileAppearance::BACKGROUND_CSS)
            ->assertJsonPath('data.appearance.has_background_image', false)
            ->assertJsonCount(5, 'data.templates')
            ->assertJsonPath('data.templates.0.background_color', '#ffffff')
            ->assertJsonPath('data.templates.1.key', 'profile02')
            ->assertJsonPath('data.templates.1.background_color', '#050505')
            ->assertJsonPath('data.templates.2.key', 'profile03')
            ->assertJsonPath('data.templates.2.background_color', '#f8fdff')
            ->assertJsonPath('data.templates.3.key', 'profile04')
            ->assertJsonPath('data.templates.3.background_color', '#050712')
            ->assertJsonPath('data.templates.4.key', 'profile05')
            ->assertJsonPath('data.templates.4.background_color', '#ffd864');

        $this->withToken($token)
            ->patchJson("/api/profile/{$profile->id}/appearance", [
                'template_key' => 'profile05',
                'background_type' => ProfileAppearance::BACKGROUND_CSS,
            ])
            ->assertOk()
            ->assertJsonPath('data.appearance.template_key', 'profile05');

        $this->assertDatabaseHas('profile_appearances', [
            'profile_id' => $profile->id,
            'template_key' => 'profile05',
            'background_type' => ProfileAppearance::BACKGROUND_CSS,
        ]);
    }

    public function test_background_upload_replaces_the_previous_file_and_is_publicly_exposed(): void
    {
        Storage::fake('profiles');
        config()->set('profile-appearance.disk', 'profiles');

        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create([
            'active' => true,
            'status' => ProfileStatus::Published,
            'alias' => 'appearance-profile',
        ]);
        $token = $user->createToken('appearance-upload', ['profile:write'])->plainTextToken;

        $firstResponse = $this->withToken($token)
            ->post("/api/profile/{$profile->id}/appearance/background-image", [
                'image' => UploadedFile::fake()->image('first.jpg', 1600, 900),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.appearance.background_type', ProfileAppearance::BACKGROUND_IMAGE)
            ->assertJsonPath('data.appearance.has_background_image', true);

        $firstPath = ProfileAppearance::query()->where('profile_id', $profile->id)->value('background_image_path');
        Storage::disk('profiles')->assertExists($firstPath);

        $this->withToken($token)
            ->post("/api/profile/{$profile->id}/appearance/background-image", [
                'image' => UploadedFile::fake()->image('second.png', 1200, 1600),
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $secondPath = ProfileAppearance::query()->where('profile_id', $profile->id)->value('background_image_path');
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('profiles')->assertMissing($firstPath);
        Storage::disk('profiles')->assertExists($secondPath);

        $this->getJson('/api/public/profiles/appearance-profile')
            ->assertOk()
            ->assertJsonPath('data.appearance.template_key', 'profile01')
            ->assertJsonPath('data.appearance.background_type', ProfileAppearance::BACKGROUND_IMAGE)
            ->assertJsonPath('data.appearance.has_background_image', true);

        $this->assertNotNull($firstResponse->json('data.appearance.background_image_url'));
    }

    public function test_appearance_endpoints_enforce_abilities_validation_and_ownership(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $profile = Profile::factory()->for($owner)->create();
        $readOnlyToken = $owner->createToken('appearance-read', ['profile:read'])->plainTextToken;
        $otherToken = $other->createToken('appearance-other', ['profile:read', 'profile:write'])->plainTextToken;

        $this->withToken($readOnlyToken)
            ->patchJson("/api/profile/{$profile->id}/appearance", ['background_type' => 'css'])
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $writeToken = $owner->createToken('appearance-write', ['profile:write'])->plainTextToken;
        $this->withToken($writeToken)
            ->getJson("/api/profile/{$profile->id}/appearance")
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $ownerToken = $owner->createToken('appearance-owner', ['profile:read', 'profile:write'])->plainTextToken;
        $this->withToken($ownerToken)
            ->patchJson("/api/profile/{$profile->id}/appearance", ['template_key' => 'unknown'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['template_key']);

        $this->withToken($ownerToken)
            ->patchJson("/api/profile/{$profile->id}/appearance", ['background_type' => 'image'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['background_type']);

        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)
            ->getJson("/api/profile/{$profile->id}/appearance")
            ->assertNotFound();
    }

    public function test_admin_can_manage_another_users_appearance(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $profile = Profile::factory()->for($owner)->create();
        $token = $admin->createToken('admin-appearance', ['profile:read', 'profile:write'])->plainTextToken;

        $this->withToken($token)
            ->patchJson("/api/profile/{$profile->id}/appearance", ['background_type' => 'css'])
            ->assertOk();
    }
}
