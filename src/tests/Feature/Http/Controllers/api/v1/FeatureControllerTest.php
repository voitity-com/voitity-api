<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Models\FeatureFlag;
use App\Models\Profile;
use App\Models\ProfileFeatureSetting;
use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use App\Models\ProfileProduct;
use App\Models\User;
use App\Services\Features\FeatureService;
use App\Services\Integrations\ProfileMediaPromptService;
use App\Services\Products\ProfileProductPromptService;
use Illuminate\Support\Str;

class FeatureControllerTest extends TestAPI
{
    public function test_admin_can_read_and_update_global_feature_flags(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $token = $admin->createToken('admin')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/features')
            ->assertOk()
            ->assertJsonPath('data.features.0.key', FeatureService::PRODUCTS)
            ->assertJsonPath('data.features.0.enabled', true);

        $response = $this->withToken($token)
            ->patchJson('/api/admin/features', [
                'features' => [
                    'products' => false,
                    'integrations' => [
                        'instagram' => true,
                        'tiktok' => false,
                        'onlyfans' => true,
                    ],
                ],
            ])
            ->assertOk();

        $features = $response->json('data.features');
        $this->assertFalse($this->feature($features, FeatureService::PRODUCTS)['enabled']);
        $this->assertTrue($this->feature($features, FeatureService::INTEGRATIONS_INSTAGRAM)['enabled']);
        $this->assertFalse($this->feature($features, FeatureService::INTEGRATIONS_TIKTOK)['enabled']);
        $this->assertTrue($this->feature($features, FeatureService::INTEGRATIONS_ONLYFANS)['enabled']);
        $this->assertDatabaseHas('feature_flags', [
            'key' => FeatureService::PRODUCTS,
            'enabled' => false,
        ]);
    }

    public function test_global_feature_flags_are_admin_only(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $token = $user->createToken('user')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/admin/features')
            ->assertForbidden()
            ->assertJsonPath('message', 'Feature flags are only available to admins.');
    }

    public function test_profile_features_respect_global_availability(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create();
        $token = $user->createToken('features', ['profile:read', 'profile:write'])->plainTextToken;

        FeatureFlag::query()
            ->where('key', FeatureService::PRODUCTS)
            ->update(['enabled' => false]);

        $response = $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/features")
            ->assertOk();

        $products = $this->feature($response->json('data.features'), FeatureService::PRODUCTS);
        $this->assertFalse($products['available']);
        $this->assertTrue($products['enabled']);
        $this->assertFalse($products['effective']);

        $response = $this->withToken($token)
            ->patchJson("/api/profile/{$profile->id}/features", [
                'features' => [
                    'products' => false,
                    'integrations' => [
                        'instagram' => true,
                    ],
                ],
            ])
            ->assertOk();

        $features = $response->json('data.features');
        $this->assertFalse($this->feature($features, FeatureService::PRODUCTS)['effective']);
        $this->assertTrue($this->feature($features, FeatureService::INTEGRATIONS_INSTAGRAM)['effective']);
        $this->assertDatabaseHas('profile_feature_settings', [
            'profile_id' => $profile->id,
            'feature_key' => FeatureService::PRODUCTS,
            'enabled' => false,
        ]);
    }

    public function test_profile_settings_block_products_integrations_and_chat_context(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::factory()->for($user)->create(['products_enabled' => true]);
        $integration = ProfileIntegration::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_user_id' => '17841400000000000',
            'username' => 'bigmelo',
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        ProfileIntegrationMedia::query()->create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            'provider_media_id' => 'media-id',
            'media_type' => 'IMAGE',
            'media_url' => 'https://example.com/media.jpg',
            'permalink' => 'https://www.instagram.com/p/media/',
            'caption' => 'Instagram media',
            'selected' => true,
            'taken_at' => now(),
        ]);
        ProfileProduct::query()->create([
            'public_id' => (string) Str::uuid(),
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'slug' => 'protein',
            'name' => 'Protein',
            'description' => 'Daily protein.',
            'image_source' => 'remote',
            'image_url' => 'https://example.com/protein.jpg',
            'destination_type' => 'external_url',
            'destination_url' => 'https://shop.example.com/protein',
            'status' => 'published',
            'fingerprint' => hash('sha256', 'protein'),
            'published_at' => now(),
        ]);
        ProfileFeatureSetting::query()->create([
            'profile_id' => $profile->id,
            'feature_key' => FeatureService::PRODUCTS,
            'enabled' => false,
        ]);
        ProfileFeatureSetting::query()->create([
            'profile_id' => $profile->id,
            'feature_key' => FeatureService::INTEGRATIONS_INSTAGRAM,
            'enabled' => false,
        ]);

        $token = $user->createToken('profile', [
            'profile:read',
            'profile:write',
            'products:read',
        ])->plainTextToken;

        $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/products")
            ->assertForbidden()
            ->assertJsonPath('message', 'Products are not enabled for this profile.');

        $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/integrations")
            ->assertOk()
            ->assertJsonPath('data.integrations', []);

        $this->withToken($token)
            ->getJson("/api/profile/{$profile->id}/integrations/instagram/media")
            ->assertForbidden()
            ->assertJsonPath('message', 'Instagram is not enabled for this profile.');

        $this->assertSame([], app(ProfileProductPromptService::class)->productsForPrompt($profile));
        $this->assertSame([], app(ProfileMediaPromptService::class)->selectedMediaForPrompt($profile));
    }

    /**
     * @param  array<int, array<string, mixed>>  $features
     * @return array<string, mixed>
     */
    private function feature(array $features, string $key): array
    {
        return collect($features)->firstWhere('key', $key) ?? [];
    }
}
