<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Models\Profile;
use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use App\Models\User;
use App\Services\Integrations\InstagramIntegrationService;
use App\Services\Integrations\ProfileMediaPromptService;
use App\Services\Integrations\TikTokIntegrationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

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

    public function test_instagram_media_endpoint_uses_caption_as_default_observation(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $integration = $this->createInstagramIntegration($profile, $user);
        $media = $this->createInstagramMedia($integration, [
            'caption' => 'Caption from Instagram',
            'observation' => null,
            'selected' => true,
        ]);
        $token = $user->createToken('test-token', ['profile:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/profile/{$profile->id}/integrations/instagram/media");

        $response->assertOk()
            ->assertJsonPath('data.media.0.id', $media->id)
            ->assertJsonPath('data.media.0.caption', 'Caption from Instagram')
            ->assertJsonPath('data.media.0.observation', 'Caption from Instagram');
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
            'instagram.graph_api_version' => 'v25.0',
            'instagram.graph_base_url' => 'https://graph.instagram.com',
            'instagram.long_lived_token_url' => 'https://graph.instagram.com/access_token',
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
            'https://graph.instagram.com/v25.0/me/media*' => Http::response([
                'data' => [[
                    'caption' => 'Media caption',
                    'id' => '18000000000000000',
                    'media_type' => 'IMAGE',
                    'media_url' => 'https://example.com/instagram.jpg',
                    'permalink' => 'https://www.instagram.com/p/test/',
                    'timestamp' => '2026-07-14T12:00:00+0000',
                ]],
            ]),
            'https://graph.instagram.com/v25.0/me*' => Http::response([
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

    public function test_tiktok_connect_url_uses_tiktok_login_parameters(): void
    {
        config([
            'tiktok.auth_url' => 'https://www.tiktok.com/v2/auth/authorize/',
            'tiktok.client_key' => 'client-key',
            'tiktok.client_secret' => 'client-secret',
            'tiktok.redirect_uri' => 'http://localhost:8000/api/integrations/tiktok/callback',
            'tiktok.scopes' => ['user.info.basic', 'video.list'],
        ]);

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $url = app(TikTokIntegrationService::class)->connectUrl($profile, $user);
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://www.tiktok.com/v2/auth/authorize/?', $url);
        $this->assertSame('client-key', $query['client_key']);
        $this->assertSame('code', $query['response_type']);
        $this->assertSame('user.info.basic,video.list', $query['scope']);
        $this->assertSame('http://localhost:8000/api/integrations/tiktok/callback', $query['redirect_uri']);
        $this->assertNotEmpty($query['state']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $query['code_challenge']);
    }

    public function test_tiktok_connect_url_does_not_force_pkce_for_web_redirects(): void
    {
        config([
            'tiktok.auth_url' => 'https://www.tiktok.com/v2/auth/authorize/',
            'tiktok.client_key' => 'client-key',
            'tiktok.client_secret' => 'client-secret',
            'tiktok.pkce_enabled' => null,
            'tiktok.redirect_uri' => 'https://api.bigmelo.com/api/integrations/tiktok/callback',
            'tiktok.scopes' => ['user.info.basic', 'video.list'],
        ]);

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $url = app(TikTokIntegrationService::class)->connectUrl($profile, $user);
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertArrayNotHasKey('code_challenge', $query);
        $this->assertArrayNotHasKey('code_challenge_method', $query);
    }

    public function test_tiktok_callback_exchanges_token_syncs_videos_and_redirects_to_admin(): void
    {
        $this->useEncryptionKey();

        config([
            'tiktok.admin_redirect_url' => 'http://localhost:3000',
            'tiktok.api_base_url' => 'https://open.tiktokapis.com',
            'tiktok.auth_url' => 'https://www.tiktok.com/v2/auth/authorize/',
            'tiktok.client_key' => 'client-key',
            'tiktok.client_secret' => 'client-secret',
            'tiktok.redirect_uri' => 'http://localhost:8000/api/integrations/tiktok/callback',
            'tiktok.scopes' => ['user.info.basic', 'video.list'],
            'tiktok.token_url' => 'https://open.tiktokapis.com/v2/oauth/token/',
        ]);

        Http::fake([
            'https://open.tiktokapis.com/v2/oauth/token/' => Http::response([
                'access_token' => 'tt-access-token',
                'expires_in' => 86400,
                'open_id' => 'open-123',
                'refresh_expires_in' => 31536000,
                'refresh_token' => 'tt-refresh-token',
                'scope' => 'user.info.basic,video.list',
                'token_type' => 'Bearer',
            ]),
            'https://open.tiktokapis.com/v2/user/info/*' => Http::response([
                'data' => [
                    'user' => [
                        'avatar_url' => 'https://example.com/avatar.jpg',
                        'display_name' => 'Bigmelo TikTok',
                        'open_id' => 'open-123',
                        'union_id' => 'union-123',
                    ],
                ],
                'error' => ['code' => 'ok', 'message' => ''],
            ]),
            'https://open.tiktokapis.com/v2/video/list/*' => Http::response([
                'data' => [
                    'cursor' => 0,
                    'has_more' => false,
                    'videos' => [[
                        'cover_image_url' => 'https://example.com/tiktok-cover.jpg',
                        'create_time' => 1784212800,
                        'duration' => 15,
                        'height' => 1920,
                        'id' => 'video-123',
                        'share_url' => 'https://www.tiktok.com/@bigmelo/video/123',
                        'title' => 'Video title',
                        'video_description' => 'Video description',
                        'width' => 1080,
                    ], [
                        'cover_image_url' => 'https://example.com/tiktok-photomode-cover.jpg',
                        'create_time' => 1784212700,
                        'duration' => 0,
                        'height' => 0,
                        'id' => 'photo-456',
                        'share_url' => 'https://www.tiktok.com/@bigmelo/photo/456',
                        'title' => 'Photo title',
                        'video_description' => 'Photo description',
                        'width' => 0,
                    ]],
                ],
                'error' => ['code' => 'ok', 'message' => ''],
            ]),
        ]);

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $connectUrl = app(TikTokIntegrationService::class)->connectUrl($profile, $user);
        $query = [];
        parse_str((string) parse_url($connectUrl, PHP_URL_QUERY), $query);

        $response = $this->getJson('/api/integrations/tiktok/callback?'.http_build_query([
            'code' => 'tt-code',
            'state' => $query['state'],
        ]));

        $response->assertRedirect(
            "http://localhost:3000/dashboard/profiles/{$profile->id}/integrations?provider=tiktok&connected=1&synced=1"
        );
        Http::assertSent(fn ($request): bool => $request->url() === 'https://open.tiktokapis.com/v2/oauth/token/'
            && $request['code'] === 'tt-code'
            && is_string($request['code_verifier'])
            && strlen($request['code_verifier']) === 64
            && hash('sha256', $request['code_verifier']) === $query['code_challenge']);
        $this->assertDatabaseHas('profile_integrations', [
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_TIKTOK,
            'provider_user_id' => 'open-123',
            'username' => 'Bigmelo TikTok',
        ]);
        $this->assertDatabaseHas('profile_integration_media', [
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_TIKTOK,
            'provider_media_id' => 'video-123',
            'media_type' => 'VIDEO',
            'media_url' => 'https://www.tiktok.com/@bigmelo/video/123',
            'thumbnail_url' => 'https://example.com/tiktok-cover.jpg',
            'observation' => 'Video title',
            'permalink' => 'https://www.tiktok.com/@bigmelo/video/123',
        ]);
        $this->assertDatabaseHas('profile_integration_media', [
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_TIKTOK,
            'provider_media_id' => 'photo-456',
            'media_type' => 'IMAGE',
            'media_url' => 'https://www.tiktok.com/@bigmelo/photo/456',
            'thumbnail_url' => 'https://example.com/tiktok-photomode-cover.jpg',
            'observation' => 'Photo title',
            'permalink' => 'https://www.tiktok.com/@bigmelo/photo/456',
        ]);
    }

    public function test_tiktok_media_endpoint_returns_connected_videos(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $integration = $this->createTikTokIntegration($profile, $user);
        $media = $this->createTikTokMedia($integration, [
            'observation' => 'Video desde Medellin',
            'selected' => true,
        ]);
        $token = $user->createToken('test-token', ['profile:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/profile/{$profile->id}/integrations/tiktok/media");

        $response->assertOk()
            ->assertJsonPath('data.integration.username', 'Bigmelo TikTok')
            ->assertJsonPath('data.media.0.id', $media->id)
            ->assertJsonPath('data.media.0.provider', ProfileIntegration::PROVIDER_TIKTOK)
            ->assertJsonPath('data.media.0.media_type', 'VIDEO')
            ->assertJsonPath('data.media.0.observation', 'Video desde Medellin')
            ->assertJsonPath('data.media.0.selected', true)
            ->assertJsonPath('data.selection_limit', 10);
    }

    public function test_tiktok_selection_limit_is_enforced(): void
    {
        config(['subscriptions.plans.starter.capabilities.integrations.tiktok.selected_media' => 1]);

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $integration = $this->createTikTokIntegration($profile, $user);
        $firstMedia = $this->createTikTokMedia($integration, ['provider_media_id' => 'video-one']);
        $secondMedia = $this->createTikTokMedia($integration, ['provider_media_id' => 'video-two']);
        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/profile/{$profile->id}/integrations/tiktok/media-selection", [
                'media' => [
                    ['id' => $firstMedia->id, 'selected' => true],
                    ['id' => $secondMedia->id, 'selected' => true],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'You can select up to 1 TikTok items.');
    }

    public function test_selected_tiktok_media_payload_is_available_for_chat_prompts(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $integration = $this->createTikTokIntegration($profile, $user);

        $this->createTikTokMedia($integration, [
            'caption' => 'Video selected',
            'observation' => 'Use this TikTok note',
            'selected' => true,
        ]);
        $this->createTikTokMedia($integration, [
            'provider_media_id' => 'unselected-video',
            'selected' => false,
        ]);

        $payload = app(ProfileMediaPromptService::class)->selectedMediaForPrompt($profile);

        $this->assertCount(1, $payload);
        $this->assertSame('TikTok', $payload[0]['provider']);
        $this->assertSame(ProfileIntegration::PROVIDER_TIKTOK, $payload[0]['provider_key']);
        $this->assertSame('social_network', $payload[0]['source_type']);
        $this->assertSame('Use this TikTok note', $payload[0]['observation']);
        $this->assertSame('Video selected', $payload[0]['caption']);
        $this->assertSame('https://example.com/tiktok-cover.jpg', $payload[0]['image_url']);
        $this->assertSame('https://www.tiktok.com/@bigmelo/video/media', $payload[0]['media_url']);
        $this->assertSame('https://example.com/tiktok-cover.jpg', $payload[0]['thumbnail_url']);
        $this->assertSame('https://www.tiktok.com/@bigmelo/video/media', $payload[0]['permalink']);
    }

    public function test_tiktok_sync_refreshes_expired_access_token_before_fetching_videos(): void
    {
        $this->useEncryptionKey();

        config([
            'tiktok.api_base_url' => 'https://open.tiktokapis.com',
            'tiktok.client_key' => 'client-key',
            'tiktok.client_secret' => 'client-secret',
            'tiktok.selection_limit' => 10,
            'tiktok.token_url' => 'https://open.tiktokapis.com/v2/oauth/token/',
        ]);

        Http::fake([
            'https://open.tiktokapis.com/v2/oauth/token/' => Http::response([
                'access_token' => 'fresh-access-token',
                'expires_in' => 86400,
                'open_id' => 'open-123',
                'refresh_expires_in' => 31536000,
                'refresh_token' => 'fresh-refresh-token',
                'scope' => 'user.info.basic,video.list',
                'token_type' => 'Bearer',
            ]),
            'https://open.tiktokapis.com/v2/user/info/*' => Http::response([
                'data' => [
                    'user' => [
                        'display_name' => 'Bigmelo TikTok',
                        'open_id' => 'open-123',
                    ],
                ],
                'error' => ['code' => 'ok', 'message' => ''],
            ]),
            'https://open.tiktokapis.com/v2/video/list/*' => Http::response([
                'data' => [
                    'cursor' => 0,
                    'has_more' => false,
                    'videos' => [],
                ],
                'error' => ['code' => 'ok', 'message' => ''],
            ]),
        ]);

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $integration = $this->createTikTokIntegration($profile, $user, [
            'access_token' => 'expired-access-token',
            'expires_at' => now()->subMinute(),
            'refresh_token' => 'old-refresh-token',
        ]);

        app(TikTokIntegrationService::class)->sync($integration);

        $integration->refresh();
        $this->assertSame('fresh-access-token', $integration->access_token);
        $this->assertSame('fresh-refresh-token', $integration->refresh_token);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://open.tiktokapis.com/v2/oauth/token/'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'old-refresh-token');
    }

    public function test_tiktok_disconnect_revokes_token_and_deletes_integration(): void
    {
        $this->useEncryptionKey();

        config([
            'tiktok.client_key' => 'client-key',
            'tiktok.client_secret' => 'client-secret',
            'tiktok.revoke_url' => 'https://open.tiktokapis.com/v2/oauth/revoke/',
        ]);

        Http::fake([
            'https://open.tiktokapis.com/v2/oauth/revoke/' => Http::response([]),
        ]);

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $integration = $this->createTikTokIntegration($profile, $user, [
            'access_token' => 'tt-access-token',
        ]);
        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/profile/{$profile->id}/integrations/tiktok");

        $response->assertOk()
            ->assertJsonPath('message', 'TikTok disconnected successfully.');
        $this->assertDatabaseMissing('profile_integrations', [
            'id' => $integration->id,
        ]);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://open.tiktokapis.com/v2/oauth/revoke/'
            && $request['token'] === 'tt-access-token');
    }

    public function test_instagram_selection_limit_is_enforced(): void
    {
        config(['subscriptions.plans.starter.capabilities.integrations.instagram.selected_media' => 1]);

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

    public function test_selected_instagram_media_prompt_uses_caption_when_observation_is_empty(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $integration = $this->createInstagramIntegration($profile, $user);

        $this->createInstagramMedia($integration, [
            'caption' => 'Caption for chat context',
            'observation' => '',
            'selected' => true,
        ]);

        $payload = app(InstagramIntegrationService::class)->selectedMediaForPrompt($profile);

        $this->assertCount(1, $payload);
        $this->assertSame('Caption for chat context', $payload[0]['observation']);
    }

    public function test_onlyfans_manual_connection_requires_consent_and_matching_profile_url(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $missingConsent = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/profile/{$profile->id}/integrations/onlyfans", [
                'username' => 'abel_creator',
                'profile_url' => 'https://onlyfans.com/abel_creator',
            ]);

        $missingConsent->assertStatus(422)
            ->assertJsonValidationErrors(['rights_confirmed', 'adult_content_confirmed']);

        $mismatchedUrl = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/profile/{$profile->id}/integrations/onlyfans", [
                'username' => 'abel_creator',
                'profile_url' => 'https://onlyfans.com/another_creator',
                'rights_confirmed' => true,
                'adult_content_confirmed' => true,
            ]);

        $mismatchedUrl->assertStatus(422)
            ->assertJsonPath('message', 'The OnlyFans username must match the profile URL.');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/profile/{$profile->id}/integrations/onlyfans", [
                'username' => '@abel_creator',
                'profile_url' => 'https://www.onlyfans.com/abel_creator/',
                'rights_confirmed' => true,
                'adult_content_confirmed' => true,
            ]);

        $response->assertOk()
            ->assertJsonPath('data.integration.provider', ProfileIntegration::PROVIDER_ONLYFANS)
            ->assertJsonPath('data.integration.username', 'abel_creator')
            ->assertJsonPath('data.integration.metadata.profile_url', 'https://onlyfans.com/abel_creator')
            ->assertJsonPath('data.integration.metadata.connection_type', 'manual_upload');
    }

    public function test_onlyfans_media_upload_is_stored_in_profile_folder_and_available_to_chat(): void
    {
        Storage::fake('profiles');
        config([
            'onlyfans.disk' => 'profiles',
            'onlyfans.folder' => 'integrations/onlyfans',
            'onlyfans.selection_limit' => 10,
        ]);

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $integration = $this->createOnlyFansIntegration($profile, $user);
        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post("/api/profile/{$profile->id}/integrations/onlyfans/media", [
                'file' => UploadedFile::fake()->create('hulk-promo.jpg', 10, 'image/jpeg'),
                'caption' => 'Hulk promotional set',
                'observation' => 'Contenido promocional inspirado en Hulk con vestuario verde.',
                'selected' => true,
                'rights_confirmed' => true,
            ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.media.provider', ProfileIntegration::PROVIDER_ONLYFANS)
            ->assertJsonPath('data.media.media_type', 'IMAGE')
            ->assertJsonPath('data.media.age_restricted', true)
            ->assertJsonPath('data.media.selected', true);

        $media = ProfileIntegrationMedia::query()->where('profile_integration_id', $integration->id)->firstOrFail();
        $this->assertStringStartsWith("integrations/onlyfans/{$profile->id}/", $media->storage_path);
        Storage::disk('profiles')->assertExists($media->storage_path);

        $payload = app(ProfileMediaPromptService::class)->selectedMediaForPrompt($profile);

        $this->assertCount(1, $payload);
        $this->assertSame('OnlyFans', $payload[0]['provider_label']);
        $this->assertSame('onlyfans', $payload[0]['provider_key']);
        $this->assertSame('Contenido promocional inspirado en Hulk con vestuario verde.', $payload[0]['observation']);
        $this->assertTrue($payload[0]['age_restricted']);
    }

    public function test_onlyfans_video_upload_requires_rights_confirmation(): void
    {
        Storage::fake('profiles');
        config(['onlyfans.disk' => 'profiles']);

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $this->createOnlyFansIntegration($profile, $user);
        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post("/api/profile/{$profile->id}/integrations/onlyfans/media", [
                'file' => UploadedFile::fake()->create('promo.mp4', 120, 'video/mp4'),
                'selected' => false,
            ], ['Accept' => 'application/json']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['rights_confirmed']);
        $this->assertDatabaseCount('profile_integration_media', 0);
    }

    public function test_onlyfans_selection_limit_is_enforced(): void
    {
        config(['subscriptions.plans.starter.capabilities.integrations.onlyfans.selected_media' => 1]);

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $integration = $this->createOnlyFansIntegration($profile, $user);
        $first = $this->createOnlyFansMedia($integration, [
            'provider_media_id' => 'onlyfans-one',
            'selected' => true,
        ]);
        $second = $this->createOnlyFansMedia($integration, ['provider_media_id' => 'onlyfans-two']);
        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/profile/{$profile->id}/integrations/onlyfans/media-selection", [
                'media' => [
                    ['id' => $second->id, 'selected' => true],
                ],
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'You can select up to 1 OnlyFans items.');
        $this->assertTrue($first->fresh()->selected);
        $this->assertFalse($second->fresh()->selected);
    }

    public function test_onlyfans_media_delete_and_disconnect_remove_stored_files(): void
    {
        Storage::fake('profiles');
        config(['onlyfans.disk' => 'profiles']);

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $integration = $this->createOnlyFansIntegration($profile, $user);
        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $firstPath = "integrations/onlyfans/{$profile->id}/first/media.jpg";
        $secondPath = "integrations/onlyfans/{$profile->id}/second/media.jpg";
        Storage::disk('profiles')->put($firstPath, 'first');
        Storage::disk('profiles')->put($secondPath, 'second');
        $first = $this->createOnlyFansMedia($integration, [
            'provider_media_id' => 'first',
            'storage_disk' => 'profiles',
            'storage_path' => $firstPath,
        ]);
        $this->createOnlyFansMedia($integration, [
            'provider_media_id' => 'second',
            'storage_disk' => 'profiles',
            'storage_path' => $secondPath,
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/profile/{$profile->id}/integrations/onlyfans/media/{$first->id}")
            ->assertOk();
        Storage::disk('profiles')->assertMissing($firstPath);
        Storage::disk('profiles')->assertExists($secondPath);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/profile/{$profile->id}/integrations/onlyfans")
            ->assertOk();
        Storage::disk('profiles')->assertMissing($secondPath);
        $this->assertDatabaseMissing('profile_integrations', ['id' => $integration->id]);
    }

    public function test_youtube_channel_and_video_are_available_to_admin_and_chat(): void
    {
        config([
            'youtube.drivers.google.api_key' => 'youtube-test-key',
            'youtube.drivers.google.base_url' => 'https://www.googleapis.com/youtube/v3',
        ]);

        Http::fake([
            'https://www.googleapis.com/youtube/v3/channels*' => Http::response([
                'items' => [[
                    'id' => 'UC1234567890123456789012',
                    'snippet' => [
                        'customUrl' => '@bigmelo',
                        'title' => 'Bigmelo',
                        'thumbnails' => ['high' => ['url' => 'https://example.com/channel.jpg']],
                    ],
                ]],
            ]),
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [[
                    'id' => 'dQw4w9WgXcQ',
                    'snippet' => [
                        'channelId' => 'UC1234567890123456789012',
                        'channelTitle' => 'Bigmelo',
                        'publishedAt' => '2026-08-01T12:00:00Z',
                        'title' => 'Bigmelo demo',
                        'thumbnails' => ['maxres' => ['url' => 'https://example.com/video.jpg']],
                    ],
                    'status' => ['embeddable' => true, 'privacyStatus' => 'public'],
                ]],
            ]),
        ]);

        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $token = $user->createToken('test-token', ['profile:read', 'profile:write'])->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/profile/{$profile->id}/integrations/youtube", [
                'channel_url' => 'https://www.youtube.com/@bigmelo',
            ])
            ->assertOk()
            ->assertJsonPath('data.integration.provider', ProfileIntegration::PROVIDER_YOUTUBE)
            ->assertJsonPath('data.integration.metadata.channel_url', 'https://www.youtube.com/@bigmelo');

        $response = $this->withToken($token)
            ->postJson("/api/profile/{$profile->id}/integrations/youtube/media", [
                'description' => 'Presentación oficial para recomendar cuando pregunten por Bigmelo.',
                'selected' => true,
                'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.media.caption', 'Bigmelo demo')
            ->assertJsonPath('data.media.channel_url', 'https://www.youtube.com/@bigmelo')
            ->assertJsonPath('data.media.thumbnail_url', 'https://example.com/video.jpg')
            ->assertJsonPath('data.media.observation', 'Presentación oficial para recomendar cuando pregunten por Bigmelo.')
            ->assertJsonPath('data.media.selected', true);

        $payload = app(ProfileMediaPromptService::class)->selectedMediaForPrompt($profile);

        $this->assertCount(1, $payload);
        $this->assertSame('youtube', $payload[0]['provider_key']);
        $this->assertSame('https://www.youtube.com/@bigmelo', $payload[0]['channel_url']);
        $this->assertSame('https://example.com/video.jpg', $payload[0]['thumbnail_url']);
        $this->assertSame('Presentación oficial para recomendar cuando pregunten por Bigmelo.', $payload[0]['observation']);

        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Goog-Api-Key', 'youtube-test-key'));
    }

    public function test_youtube_write_route_enforces_ability(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($owner)->create();

        $this->withToken($owner->createToken('read-only', ['profile:read'])->plainTextToken)
            ->postJson("/api/profile/{$profile->id}/integrations/youtube", [
                'channel_url' => 'https://www.youtube.com/@bigmelo',
            ])
            ->assertForbidden();
    }

    public function test_youtube_write_route_enforces_ownership(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $other = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($owner)->create();

        $this->withToken($other->createToken('other', ['profile:write'])->plainTextToken)
            ->postJson("/api/profile/{$profile->id}/integrations/youtube", [
                'channel_url' => 'https://www.youtube.com/@bigmelo',
            ])
            ->assertNotFound();
    }

    public function test_youtube_add_video_requires_a_description(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($owner)->create();

        $this->withToken($owner->createToken('owner', ['profile:write'])->plainTextToken)
            ->postJson("/api/profile/{$profile->id}/integrations/youtube/media", [
                'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['description']);
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
    private function createTikTokIntegration(Profile $profile, User $user, array $attributes = []): ProfileIntegration
    {
        return ProfileIntegration::query()->create(array_merge([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_TIKTOK,
            'provider_user_id' => 'open-123',
            'username' => 'Bigmelo TikTok',
            'access_token' => null,
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ], $attributes));
    }

    private function createOnlyFansIntegration(Profile $profile, User $user): ProfileIntegration
    {
        return ProfileIntegration::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_ONLYFANS,
            'provider_user_id' => 'abel_creator',
            'username' => 'abel_creator',
            'status' => ProfileIntegration::STATUS_CONNECTED,
            'metadata' => [
                'adult_content_confirmed_at' => now()->toIso8601String(),
                'connection_type' => 'manual_upload',
                'profile_url' => 'https://onlyfans.com/abel_creator',
                'rights_confirmed_at' => now()->toIso8601String(),
            ],
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createTikTokMedia(
        ProfileIntegration $integration,
        array $attributes = []
    ): ProfileIntegrationMedia {
        return ProfileIntegrationMedia::query()->create(array_merge([
            'profile_integration_id' => $integration->id,
            'profile_id' => $integration->profile_id,
            'provider' => ProfileIntegration::PROVIDER_TIKTOK,
            'provider_media_id' => 'tiktok-media-id',
            'media_type' => 'VIDEO',
            'media_url' => 'https://www.tiktok.com/@bigmelo/video/media',
            'thumbnail_url' => 'https://example.com/tiktok-cover.jpg',
            'permalink' => 'https://www.tiktok.com/@bigmelo/video/media',
            'caption' => 'TikTok caption',
            'selected' => false,
            'taken_at' => now(),
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createOnlyFansMedia(
        ProfileIntegration $integration,
        array $attributes = []
    ): ProfileIntegrationMedia {
        return ProfileIntegrationMedia::query()->create(array_merge([
            'profile_integration_id' => $integration->id,
            'profile_id' => $integration->profile_id,
            'provider' => ProfileIntegration::PROVIDER_ONLYFANS,
            'provider_media_id' => 'onlyfans-media-id',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/onlyfans-media.jpg',
            'permalink' => 'https://onlyfans.com/abel_creator',
            'caption' => 'OnlyFans promotional content',
            'age_restricted' => true,
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
