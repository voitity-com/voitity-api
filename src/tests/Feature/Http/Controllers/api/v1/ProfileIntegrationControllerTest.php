<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Models\Profile;
use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use App\Models\User;
use App\Services\Integrations\InstagramIntegrationService;
use Illuminate\Support\Facades\Http;

class ProfileIntegrationControllerTest extends TestAPI
{
    public function test_instagram_connect_url_uses_instagram_login_parameters(): void
    {
        config([
            'instagram.auth_url' => 'https://www.instagram.com/oauth/authorize',
            'instagram.client_id' => '123',
            'instagram.client_secret' => 'secret',
            'instagram.enable_fb_login' => false,
            'instagram.force_reauth' => true,
            'instagram.redirect_uri' => 'http://localhost:8000/api/integrations/instagram/callback',
            'instagram.scopes' => ['instagram_business_basic'],
        ]);

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $url = app(InstagramIntegrationService::class)->connectUrl($profile, $user);
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $url);
        $this->assertSame('0', $query['enable_fb_login']);
        $this->assertSame('1', $query['force_reauth']);
        $this->assertSame('instagram_business_basic', $query['scope']);
        $this->assertSame('http://localhost:8000/api/integrations/instagram/callback', $query['redirect_uri']);
        $this->assertNotEmpty($query['state']);
    }

    public function test_instagram_media_endpoint_returns_connected_media(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $integration = $this->createInstagramIntegration($profile, $user);
        $media = $this->createInstagramMedia($integration, [
            'observation' => 'Foto de Medellin',
            'selected' => true,
        ]);
        $token = $user->createToken('test-token', ['profile:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/profile/{$profile->id}/integrations/instagram/media");

        $response->assertOk()
            ->assertJsonPath('data.integration.username', 'bigmelo')
            ->assertJsonPath('data.media.0.id', $media->id)
            ->assertJsonPath('data.media.0.observation', 'Foto de Medellin')
            ->assertJsonPath('data.media.0.selected', true)
            ->assertJsonPath('data.selection_limit', 10);
    }

    public function test_instagram_callback_exchanges_token_syncs_media_and_redirects_to_admin(): void
    {
        $this->useEncryptionKey();

        config([
            'instagram.admin_redirect_url' => 'http://localhost:3000',
            'instagram.auth_url' => 'https://www.instagram.com/oauth/authorize',
            'instagram.client_id' => '123',
            'instagram.client_secret' => 'secret',
            'instagram.enable_fb_login' => false,
            'instagram.force_reauth' => true,
            'instagram.graph_base_url' => 'https://graph.instagram.com',
            'instagram.redirect_uri' => 'http://localhost:8000/api/integrations/instagram/callback',
            'instagram.scopes' => ['instagram_business_basic'],
            'instagram.token_url' => 'https://api.instagram.com/oauth/access_token',
        ]);

        Http::fake([
            'https://api.instagram.com/oauth/access_token' => Http::response([
                'access_token' => 'short-token',
                'user_id' => '17841400000000000',
            ]),
            'https://graph.instagram.com/access_token*' => Http::response([
                'access_token' => 'long-token',
                'expires_in' => 5183944,
                'token_type' => 'bearer',
            ]),
            'https://graph.instagram.com/me/media*' => Http::response([
                'data' => [[
                    'caption' => 'Media caption',
                    'id' => '18000000000000000',
                    'media_type' => 'IMAGE',
                    'media_url' => 'https://example.com/instagram.jpg',
                    'permalink' => 'https://www.instagram.com/p/test/',
                    'timestamp' => '2026-07-14T12:00:00+0000',
                ]],
            ]),
            'https://graph.instagram.com/me*' => Http::response([
                'account_type' => 'BUSINESS',
                'id' => '17841400000000000',
                'media_count' => 1,
                'username' => 'bigmelo',
            ]),
        ]);

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $connectUrl = app(InstagramIntegrationService::class)->connectUrl($profile, $user);
        $query = [];
        parse_str((string) parse_url($connectUrl, PHP_URL_QUERY), $query);

        $response = $this->getJson('/api/integrations/instagram/callback?'.http_build_query([
            'code' => 'ig-code',
            'state' => $query['state'],
        ]));

        $response->assertRedirect(
            "http://localhost:3000/dashboard/profiles/{$profile->id}/integrations?provider=instagram&connected=1&synced=1"
        );
        $this->assertDatabaseHas('profile_integrations', [
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_user_id' => '17841400000000000',
            'username' => 'bigmelo',
        ]);
        $this->assertDatabaseHas('profile_integration_media', [
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_media_id' => '18000000000000000',
            'media_url' => 'https://example.com/instagram.jpg',
            'observation' => 'Media caption',
            'permalink' => 'https://www.instagram.com/p/test/',
        ]);
    }

    public function test_instagram_selection_limit_is_enforced(): void
    {
        config(['instagram.selection_limit' => 1]);

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $integration = $this->createInstagramIntegration($profile, $user);
        $firstMedia = $this->createInstagramMedia($integration, ['provider_media_id' => 'media-one']);
        $secondMedia = $this->createInstagramMedia($integration, ['provider_media_id' => 'media-two']);
        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/profile/{$profile->id}/integrations/instagram/media-selection", [
                'media' => [
                    ['id' => $firstMedia->id, 'selected' => true],
                    ['id' => $secondMedia->id, 'selected' => true],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'You can select up to 1 Instagram items.');
    }

    public function test_selected_instagram_media_payload_is_available_for_chat_prompts(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $integration = $this->createInstagramIntegration($profile, $user);

        $this->createInstagramMedia($integration, [
            'caption' => 'Caption selected',
            'observation' => 'Use this note',
            'selected' => true,
        ]);
        $this->createInstagramMedia($integration, [
            'provider_media_id' => 'unselected-media',
            'selected' => false,
        ]);

        $payload = app(InstagramIntegrationService::class)->selectedMediaForPrompt($profile);

        $this->assertCount(1, $payload);
        $this->assertSame('Instagram', $payload[0]['provider']);
        $this->assertSame('Use this note', $payload[0]['observation']);
        $this->assertSame('Caption selected', $payload[0]['caption']);
        $this->assertSame('https://example.com/media.jpg', $payload[0]['image_url']);
        $this->assertSame('https://www.instagram.com/p/media/', $payload[0]['permalink']);
    }

    private function createInstagramIntegration(Profile $profile, User $user): ProfileIntegration
    {
        return ProfileIntegration::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_user_id' => '17841400000000000',
            'username' => 'bigmelo',
            'access_token' => null,
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createInstagramMedia(
        ProfileIntegration $integration,
        array $attributes = []
    ): ProfileIntegrationMedia {
        return ProfileIntegrationMedia::query()->create(array_merge([
            'profile_integration_id' => $integration->id,
            'profile_id' => $integration->profile_id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_media_id' => 'media-id',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/media.jpg',
            'permalink' => 'https://www.instagram.com/p/media/',
            'caption' => 'Caption',
            'selected' => false,
            'taken_at' => now(),
        ], $attributes));
    }

    private function useEncryptionKey(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        $this->app->forgetInstance('encrypter');
    }
}
