<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Models\Profile;
use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use App\Models\User;
use App\Services\Integrations\ProfileMediaPromptService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\Support\EnablesFeaturesForTestProfiles;

class ProfileOtherIntegrationControllerTest extends TestAPI
{
    use EnablesFeaturesForTestProfiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->enableFeaturesForTestProfiles();
    }

    public function test_destination_catalog_returns_stable_codes_and_localized_labels(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $token = $user->createToken('catalog', ['profile:read'])->plainTextToken;

        $spanish = $this->withToken($token)
            ->getJson('/api/profile/integration-destinations?locale=es')
            ->assertOk()
            ->assertJsonPath('data.locale', 'es');
        $english = $this->withToken($token)
            ->getJson('/api/profile/integration-destinations?locale=en')
            ->assertOk()
            ->assertJsonPath('data.locale', 'en');

        $spanishWebsite = collect($spanish->json('data.destinations'))->firstWhere('value', 'website');
        $englishWebsite = collect($english->json('data.destinations'))->firstWhere('value', 'website');

        $this->assertSame('Sitio web', $spanishWebsite['label']);
        $this->assertSame('Visitar el sitio web', $spanishWebsite['action_label']);
        $this->assertSame('Visit the website', $englishWebsite['action_label']);
        $this->assertNotNull(collect($spanish->json('data.destinations'))->firstWhere('value', 'other'));
        $this->assertGreaterThanOrEqual(35, count($spanish->json('data.destinations')));
    }

    public function test_upload_stores_media_creates_integration_logs_and_exposes_localized_chat_action(): void
    {
        Storage::fake('profiles');
        config([
            'other.disk' => 'profiles',
            'other.folder' => 'integrations/other',
        ]);

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create(['locale' => 'es']);
        $token = $user->createToken('write', ['profile:write'])->plainTextToken;
        Log::spy();

        $response = $this->withToken($token)
            ->post("/api/profile/{$profile->id}/integrations/other/media?locale=es", [
                'file' => UploadedFile::fake()->create('lanzamiento.jpg', 25, 'image/jpeg'),
                'description' => 'Lee la entrevista completa sobre el lanzamiento.',
                'link' => 'https://diario.example.com/entrevista',
                'destination_type' => 'news_media',
                'selected' => true,
                'rights_confirmed' => true,
            ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.integration.provider', ProfileIntegration::PROVIDER_OTHER)
            ->assertJsonPath('data.media.description', null)
            ->assertJsonPath('data.media.caption', 'Lee la entrevista completa sobre el lanzamiento.')
            ->assertJsonPath('data.media.destination_type', 'news_media')
            ->assertJsonPath('data.media.destination_label', 'Diario / Medio')
            ->assertJsonPath('data.media.action_type', 'read_on')
            ->assertJsonPath('data.media.action_label', 'Leer en el medio')
            ->assertJsonPath('data.media.selected', true);

        $media = ProfileIntegrationMedia::query()->where('profile_id', $profile->id)->firstOrFail();
        $this->assertStringStartsWith("integrations/other/{$profile->id}/", $media->storage_path);
        Storage::disk('profiles')->assertExists($media->storage_path);
        $this->assertSame('news_media', $media->metadata['destination_type']);
        $this->assertSame('read_on', $media->metadata['action_type']);

        $promptMedia = app(ProfileMediaPromptService::class)->selectedMediaForPrompt($profile);
        $this->assertCount(1, $promptMedia);
        $this->assertSame('other', $promptMedia[0]['provider_key']);
        $this->assertSame('Diario / Medio', $promptMedia[0]['provider_label']);
        $this->assertSame('Leer en el medio', $promptMedia[0]['action_label']);

        Log::shouldHaveReceived('info')
            ->with('Other integration media uploaded.', Mockery::on(fn (array $context): bool => $context['profile_id'] === $profile->id
                && $context['media_id'] === $media->id
                && $context['storage_disk'] === 'profiles'
            ))
            ->once();
    }

    public function test_video_upload_uses_the_same_configured_disk_and_returns_the_destination_action(): void
    {
        Storage::fake('profiles');
        config([
            'other.disk' => 'profiles',
            'other.folder' => 'integrations/other',
        ]);

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create(['locale' => 'en']);
        $token = $user->createToken('write', ['profile:write'])->plainTextToken;

        $response = $this->withToken($token)
            ->post("/api/profile/{$profile->id}/integrations/other/media?locale=en", [
                'file' => UploadedFile::fake()->create('launch.mp4', 512, 'video/mp4'),
                'description' => 'Watch the launch video.',
                'link' => 'https://www.tiktok.com/@creator/video/123',
                'destination_type' => 'tiktok',
                'selected' => false,
                'rights_confirmed' => true,
            ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.media.media_type', 'VIDEO')
            ->assertJsonPath('data.media.destination_type', 'tiktok')
            ->assertJsonPath('data.media.action_label', 'View on TikTok');

        $media = ProfileIntegrationMedia::query()->where('profile_id', $profile->id)->firstOrFail();
        $this->assertStringEndsWith('/media.mp4', $media->storage_path);
        Storage::disk('profiles')->assertExists($media->storage_path);
    }

    public function test_upload_validates_rights_link_and_custom_destination_label(): void
    {
        Storage::fake('profiles');
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $token = $user->createToken('write', ['profile:write'])->plainTextToken;

        $this->withToken($token)
            ->post("/api/profile/{$profile->id}/integrations/other/media", [
                'file' => UploadedFile::fake()->create('promo.jpg', 10, 'image/jpeg'),
                'description' => 'Promoción',
                'link' => 'javascript:alert(1)',
                'destination_type' => 'other',
            ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['custom_destination_label', 'link', 'rights_confirmed']);

        $this->assertDatabaseMissing('profile_integrations', [
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_OTHER,
        ]);
        $this->assertDatabaseCount('profile_integration_media', 0);
    }

    public function test_media_can_be_updated_and_other_custom_label_is_localized_without_persisting_translation(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create(['locale' => 'en']);
        $integration = $this->createIntegration($profile, $user);
        $media = $this->createMedia($integration);
        $token = $user->createToken('write', ['profile:write'])->plainTextToken;

        $response = $this->withToken($token)
            ->patchJson("/api/profile/{$profile->id}/integrations/other/media/{$media->id}?locale=en", [
                'description' => 'Watch the complete conversation.',
                'link' => 'https://community.example.com/interview',
                'destination_type' => 'other',
                'custom_destination_label' => 'Creator Hub',
                'selected' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.media.destination_type', 'other')
            ->assertJsonPath('data.media.destination_label', 'Creator Hub')
            ->assertJsonPath('data.media.action_label', 'View on Creator Hub')
            ->assertJsonPath('data.media.caption', 'Watch the complete conversation.')
            ->assertJsonPath('data.media.selected', true);

        $media->refresh();
        $this->assertSame('view_on', $media->metadata['action_type']);
        $this->assertSame('Creator Hub', $media->metadata['custom_destination_label']);
        $this->assertArrayNotHasKey('action_label', $media->metadata);
        $this->assertArrayNotHasKey('destination_label', $media->metadata);
    }

    public function test_selection_limit_and_cross_profile_media_ownership_are_enforced(): void
    {
        config(['subscriptions.plans.starter.capabilities.integrations.other.selected_media' => 1]);

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $integration = $this->createIntegration($profile, $user);
        $first = $this->createMedia($integration, ['provider_media_id' => 'first']);
        $second = $this->createMedia($integration, ['provider_media_id' => 'second']);
        $otherProfile = Profile::factory()->for($user)->create();
        $otherIntegration = $this->createIntegration($otherProfile, $user);
        $foreign = $this->createMedia($otherIntegration, ['provider_media_id' => 'foreign']);
        $token = $user->createToken('write', ['profile:write'])->plainTextToken;

        $this->withToken($token)
            ->putJson("/api/profile/{$profile->id}/integrations/other/media-selection", [
                'media' => [
                    ['id' => $first->id, 'selected' => true],
                    ['id' => $second->id, 'selected' => true],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'You can select up to 1 Other items.');

        $this->withToken($token)
            ->patchJson("/api/profile/{$profile->id}/integrations/other/media/{$foreign->id}", [
                'description' => 'Foreign',
                'link' => 'https://example.com/foreign',
                'destination_type' => 'website',
            ])
            ->assertNotFound()
            ->assertJsonPath('message', 'Other integration media was not found.');

        $this->assertFalse($first->fresh()->selected);
        $this->assertFalse($second->fresh()->selected);
    }

    public function test_read_and_write_abilities_ownership_and_admin_access_are_enforced(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($owner)->create();
        $otherUser = User::factory()->create(['role' => 'user']);
        $admin = User::factory()->create(['role' => 'admin']);

        $missingAbility = $owner->createToken('missing', ['user:read'])->plainTextToken;
        $otherToken = $otherUser->createToken('other', ['profile:read'])->plainTextToken;
        $adminToken = $admin->createToken('admin', ['profile:read'])->plainTextToken;

        $this->withToken($missingAbility)
            ->getJson("/api/profile/{$profile->id}/integrations/other/media")
            ->assertForbidden();
        $this->app['auth']->forgetGuards();
        $otherResponse = $this->flushHeaders()->withToken($otherToken)
            ->getJson("/api/profile/{$profile->id}/integrations/other/media");
        $this->assertSame(404, $otherResponse->status(), $otherResponse->getContent());
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->withToken($adminToken)
            ->getJson("/api/profile/{$profile->id}/integrations/other/media")
            ->assertOk()
            ->assertJsonPath('data.integration', null)
            ->assertJsonPath('data.media', []);
    }

    public function test_delete_and_disconnect_remove_local_or_s3_disk_objects_through_configured_disk(): void
    {
        Storage::fake('profiles');
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $integration = $this->createIntegration($profile, $user);
        $firstPath = "integrations/other/{$profile->id}/first/media.jpg";
        $secondPath = "integrations/other/{$profile->id}/second/media.mp4";
        Storage::disk('profiles')->put($firstPath, 'first');
        Storage::disk('profiles')->put($secondPath, 'second');
        $first = $this->createMedia($integration, [
            'provider_media_id' => 'first',
            'storage_disk' => 'profiles',
            'storage_path' => $firstPath,
        ]);
        $this->createMedia($integration, [
            'provider_media_id' => 'second',
            'media_type' => 'VIDEO',
            'storage_disk' => 'profiles',
            'storage_path' => $secondPath,
        ]);
        $token = $user->createToken('write', ['profile:write'])->plainTextToken;

        $this->withToken($token)
            ->deleteJson("/api/profile/{$profile->id}/integrations/other/media/{$first->id}")
            ->assertOk();
        Storage::disk('profiles')->assertMissing($firstPath);
        Storage::disk('profiles')->assertExists($secondPath);

        $this->withToken($token)
            ->deleteJson("/api/profile/{$profile->id}/integrations/other")
            ->assertOk();
        Storage::disk('profiles')->assertMissing($secondPath);
        $this->assertDatabaseMissing('profile_integrations', ['id' => $integration->id]);
        $this->assertDatabaseMissing('profile_integration_media', ['profile_integration_id' => $integration->id]);
    }

    private function createIntegration(Profile $profile, User $user): ProfileIntegration
    {
        return ProfileIntegration::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_OTHER,
            'provider_user_id' => (string) $profile->id,
            'status' => ProfileIntegration::STATUS_CONNECTED,
            'metadata' => ['connection_type' => 'manual_upload'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createMedia(ProfileIntegration $integration, array $attributes = []): ProfileIntegrationMedia
    {
        return ProfileIntegrationMedia::query()->create(array_merge([
            'profile_integration_id' => $integration->id,
            'profile_id' => $integration->profile_id,
            'provider' => ProfileIntegration::PROVIDER_OTHER,
            'provider_media_id' => 'other-media-id',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/media.jpg',
            'permalink' => 'https://example.com/article',
            'caption' => 'Article description',
            'observation' => 'Article description',
            'selected' => false,
            'taken_at' => now(),
            'metadata' => [
                'action_type' => 'read_on',
                'destination_type' => 'blog',
                'source_type' => 'manual_upload',
            ],
        ], $attributes));
    }
}
