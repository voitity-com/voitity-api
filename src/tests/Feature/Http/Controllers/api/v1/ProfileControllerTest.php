<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Enums\ActivationEventType;
use App\Enums\ProfileSourceStatus;
use App\Enums\ProfileStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionUsageType;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\ProfileSource;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\User;
use App\Models\Voice;
use App\Services\Features\FeatureService;
use Illuminate\Support\Facades\Hash;

class ProfileControllerTest extends TestAPI
{
    /**
     * Profile api endpoint
     */
    const ENDPOINT_PROFILE = '/api/profile';

    public function test_store_fails_with_invalid_fields()
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken())
            ->postJson(self::ENDPOINT_PROFILE, [
                'name' => '', // empty
                'alias' => str_repeat('a', 101),
                // 'description' => missing
                'genre' => 'toolongforgenre', // too long
                'locale' => 'fr',
                // 'personality' => missing
            ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'alias', 'description', 'genre', 'locale', 'personality']);
    }

    public function test_unauthorized_user_can_not_create_profile()
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->faker->word())
            ->json('POST', self::ENDPOINT_PROFILE, []);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_can_create_a_profile()
    {
        $profile_data = [
            'name' => $this->faker->name,
            'alias' => 'Demo Alias',
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'locale' => 'en',
            'personality' => $this->faker->text(100),
        ];

        $token = $this->getToken();
        $user = User::where('email', 'voitity@gmail.com')->firstOrFail();
        $this->createActiveSubscriptionFor($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_PROFILE, $profile_data);

        $response->assertJsonPath('message', 'Profile created successfully.');
        $response->assertStatus(200);

        $response_content = json_decode($response->getContent());

        $new_profile = Profile::find($response_content->data->id);
        $baseVoice = Voice::where('profile_id', $new_profile->id)->first();

        $this->assertNotNull($baseVoice);
        $this->assertEquals($profile_data['name'], $new_profile->name);
        $this->assertEquals($profile_data['alias'], $new_profile->alias);
        $this->assertEquals($profile_data['description'], $new_profile->description);
        $this->assertSame('en', $new_profile->locale);
        $this->assertFalse((bool) $new_profile->active);
        $this->assertTrue((bool) $new_profile->products_enabled);
        $this->assertSame(ProfileStatus::Draft, $new_profile->status);
        $this->assertSame($new_profile->user_id, $baseVoice->user_id);
        $this->assertSame($new_profile->name, $baseVoice->name);
        $this->assertSame($new_profile->description, $baseVoice->description);
        $this->assertSame('en', $baseVoice->language_code);
        $this->assertTrue((bool) $baseVoice->active);
        $this->assertNull($baseVoice->source);
        $this->assertNull($baseVoice->source_voice_id);
        $response->assertJsonPath('data.alias', $profile_data['alias']);
        $response->assertJsonPath('data.locale', 'en');
        $response->assertJsonPath('data.active', false);
        $response->assertJsonPath('data.status', ProfileStatus::Draft->value);
        $response->assertJsonPath('data.voice', false);
        $response->assertJsonPath('data.voice_id', $baseVoice->id);
        $response->assertJsonPath('data.publication.can_activate', false);
        $response->assertJsonPath('data.publication.is_published', false);
        $settings = collect($response->json('data.feature_settings'))->keyBy('key');

        $catalog = collect(app(FeatureService::class)->catalog());
        $profileFeatureKeys = $catalog
            ->filter(fn (array $feature): bool => (bool) ($feature['profile_configurable'] ?? true))
            ->keys();
        $this->assertCount($catalog->count(), $settings);

        $defaultEnabledFeatures = collect([
            FeatureService::PRODUCTS,
            FeatureService::INTEGRATIONS_TIKTOK,
            FeatureService::INTEGRATIONS_YOUTUBE,
            FeatureService::INTEGRATIONS_OTHER,
        ]);

        foreach ($profileFeatureKeys as $featureKey) {
            $this->assertTrue($settings->has($featureKey));
            $this->assertTrue($settings[$featureKey]['available']);
            $this->assertSame($defaultEnabledFeatures->contains($featureKey), $settings[$featureKey]['enabled']);
            $this->assertSame($defaultEnabledFeatures->contains($featureKey), $settings[$featureKey]['effective']);
            $this->assertDatabaseHas('profile_feature_settings', [
                'profile_id' => $new_profile->id,
                'feature_key' => $featureKey,
                'enabled' => $defaultEnabledFeatures->contains($featureKey),
            ]);
        }
        $this->assertTrue($settings[FeatureService::DOMAINS_CUSTOM]['available']);
        $this->assertTrue($settings[FeatureService::DOMAINS_CUSTOM]['enabled']);
        $this->assertTrue($settings[FeatureService::DOMAINS_CUSTOM]['effective']);
        $this->assertFalse($settings[FeatureService::DOMAINS_CUSTOM]['profile_configurable']);
        $this->assertDatabaseMissing('profile_feature_settings', [
            'profile_id' => $new_profile->id,
            'feature_key' => FeatureService::DOMAINS_CUSTOM,
        ]);
        $this->assertDatabaseHas('activation_events', [
            'user_id' => $user->id,
            'profile_id' => $new_profile->id,
            'event_type' => ActivationEventType::ProfileCreated->value,
        ]);
        $this->assertDatabaseHas('profile_widgets', [
            'profile_id' => $new_profile->id,
            'enabled' => false,
        ]);
        $this->assertDatabaseHas('subscription_uses', [
            'user_id' => $new_profile->user_id,
            'profile_id' => $new_profile->id,
            'usage_type' => SubscriptionUsageType::ProfileCreated->value,
            'profiles_used' => 1,
            'idempotency_key' => "profile-created:{$new_profile->id}",
        ]);
        $this->assertSame(0, (int) $user->subscriptions()->where('active', true)->firstOrFail()->limit()->firstOrFail()->profiles_remaining);

        $secondResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_PROFILE, array_merge($profile_data, [
                'name' => $this->faker->name,
                'alias' => 'Second Alias',
            ]));

        $secondResponse->assertStatus(402);
        $secondResponse->assertJsonPath('message', 'Subscription limit exceeded.');
        $this->assertDatabaseMissing('profiles', [
            'user_id' => $user->id,
            'alias' => 'Second Alias',
        ]);
    }

    public function test_user_can_not_create_profile_without_alias(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken())
            ->postJson(self::ENDPOINT_PROFILE, [
                'name' => $this->faker->name,
                'description' => $this->faker->text(200),
                'genre' => 'male',
                'personality' => $this->faker->text(100),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['alias']);
    }

    public function test_user_can_not_create_profile_with_existing_alias(): void
    {
        Profile::factory()->create(['alias' => 'existing-alias']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken())
            ->postJson(self::ENDPOINT_PROFILE, [
                'name' => $this->faker->name,
                'alias' => 'existing-alias',
                'description' => $this->faker->text(200),
                'genre' => 'male',
                'personality' => $this->faker->text(100),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['alias']);
    }

    public function test_user_can_not_create_profile_with_reserved_alias(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken())
            ->postJson(self::ENDPOINT_PROFILE, [
                'name' => $this->faker->name,
                'alias' => 'landing',
                'description' => $this->faker->text(200),
                'genre' => 'male',
                'personality' => $this->faker->text(100),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['alias']);
    }

    public function test_unauthorized_user_can_not_list_profiles()
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->faker->word())
            ->json('GET', self::ENDPOINT_PROFILE);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_without_profile_read_ability_can_not_list_profiles()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->json('GET', self::ENDPOINT_PROFILE);

        $response->assertStatus(403);
    }

    public function test_user_can_list_only_his_profiles()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $otherUser = User::factory()->create(['role' => 'admin']);

        $profileA = Profile::create([
            'user_id' => $user->id,
            'alias' => 'Profile A',
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);
        $profileB = Profile::create([
            'user_id' => $user->id,
            'alias' => 'Profile B',
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'female',
            'personality' => $this->faker->text(100),
        ]);
        $otherProfile = Profile::create([
            'user_id' => $otherUser->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);
        Voice::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profileA->id,
            'name' => 'Profile A voice',
            'description' => 'Profile A voice description',
            'language_code' => 'en',
            'source_voice_id' => 'provider-voice-id',
            'source' => 'elevenlabs',
        ]);
        Voice::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profileB->id,
            'source_voice_id' => '',
            'source' => '',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->json('GET', self::ENDPOINT_PROFILE);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Profiles retrieved successfully.');
        $response->assertJsonPath('data.total', 2);
        $response->assertJsonCount(2, 'data.profiles');

        $profileIds = collect($response->json('data.profiles'))->pluck('id')->all();
        $profileUserIds = collect($response->json('data.profiles'))->pluck('user_id')->unique()->values()->all();

        $this->assertContains($profileA->id, $profileIds);
        $this->assertContains($profileB->id, $profileIds);
        $this->assertNotContains($otherProfile->id, $profileIds);
        $this->assertEquals([$user->id], $profileUserIds);

        $profilesById = collect($response->json('data.profiles'))->keyBy('id');
        $this->assertSame('Profile A', $profilesById[$profileA->id]['alias']);
        $this->assertSame('Profile B', $profilesById[$profileB->id]['alias']);
        $this->assertSame(ProfileStatus::Published->value, $profilesById[$profileA->id]['status']);
        $this->assertSame(ProfileStatus::Draft->value, $profilesById[$profileB->id]['status']);
        $this->assertTrue($profilesById[$profileA->id]['voice']);
        $this->assertFalse($profilesById[$profileB->id]['voice']);
        $this->assertSame('Profile A voice', $profilesById[$profileA->id]['voice_name']);
        $this->assertSame('Profile A voice description', $profilesById[$profileA->id]['voice_description']);
        $this->assertSame('en', $profilesById[$profileA->id]['voice_language_code']);
    }

    public function test_unauthorized_user_can_not_show_a_profile()
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->faker->word())
            ->json('GET', self::ENDPOINT_PROFILE.'/100');

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_can_not_show_profile_if_he_is_not_owner()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $reader = User::factory()->create([
            'role' => 'user',
            'password' => Hash::make('test123'),
        ]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($reader->email, 'test123'))
            ->json('GET', self::ENDPOINT_PROFILE.'/'.$profile->id);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Profile not found.');
    }

    public function test_admin_can_show_profile_if_he_is_not_owner()
    {
        $owner = User::factory()->create(['role' => 'user']);
        $admin = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('test123'),
        ]);
        $profile = Profile::factory()->create(['user_id' => $owner->id]);

        $this->withHeader('Authorization', 'Bearer '.$this->getToken($admin->email, 'test123'))
            ->getJson(self::ENDPOINT_PROFILE.'/'.$profile->id)
            ->assertOk()
            ->assertJsonPath('message', 'Profile retrieved successfully.')
            ->assertJsonPath('data.id', $profile->id);
    }

    public function test_user_can_show_profile()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'alias' => 'Show Alias',
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
            'status' => ProfileStatus::Hidden,
        ]);
        Voice::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'name' => 'Show profile voice',
            'description' => 'Show profile voice description',
            'language_code' => 'en',
            'source_voice_id' => 'provider-voice-id',
            'source' => 'elevenlabs',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->json('GET', self::ENDPOINT_PROFILE.'/'.$profile->id);

        $response->assertJsonPath('message', 'Profile retrieved successfully.');
        $response->assertJsonPath('data.id', $profile->id);
        $response->assertJsonPath('data.name', $profile->name);
        $response->assertJsonPath('data.alias', $profile->alias);
        $response->assertJsonPath('data.description', $profile->description);
        $response->assertJsonPath('data.status', ProfileStatus::Hidden->value);
        $response->assertJsonPath('data.voice', true);
        $response->assertJsonPath('data.voice_name', 'Show profile voice');
        $response->assertJsonPath('data.voice_description', 'Show profile voice description');
        $response->assertJsonPath('data.voice_language_code', 'en');
        $response->assertStatus(200);
    }

    public function test_user_can_show_profile_by_alias_without_owner_validation()
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $reader = User::factory()->create(['role' => 'api']);
        $profile = Profile::create([
            'user_id' => $owner->id,
            'alias' => 'public-alias',
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);
        Voice::factory()->create([
            'user_id' => $owner->id,
            'profile_id' => $profile->id,
            'name' => 'Alias profile voice',
            'description' => 'Alias profile voice description',
            'language_code' => 'en',
            'source_voice_id' => 'provider-voice-id',
            'source' => 'elevenlabs',
        ]);

        $token = $reader->createToken('test-token', ['profile:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->json('GET', self::ENDPOINT_PROFILE.'/alias/'.$profile->alias);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Profile retrieved successfully.');
        $response->assertJsonPath('data.id', $profile->id);
        $response->assertJsonPath('data.user_id', $owner->id);
        $response->assertJsonPath('data.alias', $profile->alias);
        $response->assertJsonPath('data.voice_name', 'Alias profile voice');
        $response->assertJsonPath('data.voice_description', 'Alias profile voice description');
        $response->assertJsonPath('data.voice_language_code', 'en');
        $response->assertJsonPath('data.status', ProfileStatus::Published->value);
        $response->assertJsonPath('data.voice', true);
    }

    public function test_user_can_not_show_profile_by_alias_when_profile_is_not_public()
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $reader = User::factory()->create(['role' => 'api']);
        $draftProfile = Profile::create([
            'user_id' => $owner->id,
            'alias' => 'draft-alias',
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
            'status' => ProfileStatus::Draft,
            'active' => true,
        ]);
        $inactivePublishedProfile = Profile::create([
            'user_id' => $owner->id,
            'alias' => 'inactive-published-alias',
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
            'status' => ProfileStatus::Published,
            'active' => false,
        ]);
        $token = $reader->createToken('test-token', ['profile:read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->json('GET', self::ENDPOINT_PROFILE.'/alias/'.$draftProfile->alias)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Profile not found.');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->json('GET', self::ENDPOINT_PROFILE.'/alias/'.$inactivePublishedProfile->alias)
            ->assertStatus(404)
            ->assertJsonPath('message', 'Profile not found.');
    }

    public function test_user_without_profile_read_ability_can_not_show_profile_by_alias()
    {
        $user = User::factory()->create(['role' => 'api']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'alias' => 'private-alias',
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->json('GET', self::ENDPOINT_PROFILE.'/alias/'.$profile->alias);

        $response->assertStatus(403);
    }

    public function test_unauthorized_user_can_not_list_social_networks()
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->faker->word())
            ->json('GET', self::ENDPOINT_PROFILE.'/social-networks');

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_without_profile_read_ability_can_not_list_social_networks()
    {
        $user = User::factory()->create(['role' => 'user']);
        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->json('GET', self::ENDPOINT_PROFILE.'/social-networks');

        $response->assertStatus(403);
    }

    public function test_user_can_list_social_networks()
    {
        $user = User::factory()->create(['role' => 'user']);
        $token = $user->createToken('test-token', ['profile:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->json('GET', self::ENDPOINT_PROFILE.'/social-networks');

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Social networks retrieved successfully.');
        $response->assertJsonPath('data.networks.facebook.name', 'Facebook');
        $response->assertJsonPath(
            'data.networks.github.icon',
            'https://bigmelo-prod-profiles-139194331469.s3.amazonaws.com/icons/github.png'
        );
    }

    public function test_unauthorized_user_can_not_update_a_profile()
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->faker->word())
            ->json('PATCH', self::ENDPOINT_PROFILE.'/100', []);

        $response_content = json_decode($response->getContent());

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_can_not_update_profile_if_he_is_not_owner()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken())
            ->json('PATCH', self::ENDPOINT_PROFILE.'/'.$profile->id, []);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Profile not found.');
    }

    public function test_user_can_update_profile()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $new_data = [
            'name' => $this->faker->name,
            'alias' => 'Updated Alias',
            'description' => $this->faker->text(200),
            'genre' => 'female',
            'locale' => 'en',
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->json('PATCH', self::ENDPOINT_PROFILE.'/'.$profile->id, $new_data);

        $response->assertJsonPath('message', 'Profile updated successfully.');
        $response->assertStatus(200);

        $new_profile = Profile::find($profile->id);
        $this->assertEquals($new_data['name'], $new_profile->name);
        $this->assertEquals($new_data['alias'], $new_profile->alias);
        $this->assertEquals($new_data['description'], $new_profile->description);
        $this->assertEquals($new_data['genre'], $new_profile->genre);
        $this->assertSame('en', $new_profile->locale);
        $this->assertFalse((bool) $new_profile->active);
        $this->assertSame(ProfileStatus::Draft, $new_profile->status);
        $response->assertJsonPath('data.alias', $new_data['alias']);
        $response->assertJsonPath('data.locale', 'en');
        $response->assertJsonPath('data.status', ProfileStatus::Draft->value);
    }

    public function test_user_can_update_profile_keeping_same_alias()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'alias' => 'same-alias',
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->json('PATCH', self::ENDPOINT_PROFILE.'/'.$profile->id, [
                'alias' => 'same-alias',
                'name' => 'Updated name',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.alias', 'same-alias');
        $this->assertSame('same-alias', $profile->fresh()->alias);
    }

    public function test_user_can_not_update_profile_with_existing_alias()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'alias' => 'current-alias',
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);
        Profile::factory()->create(['alias' => 'taken-alias']);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->json('PATCH', self::ENDPOINT_PROFILE.'/'.$profile->id, [
                'alias' => 'taken-alias',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['alias']);
        $this->assertSame('current-alias', $profile->fresh()->alias);
    }

    public function test_user_can_not_update_profile_with_reserved_alias_regardless_of_case(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'alias' => 'current-alias',
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->json('PATCH', self::ENDPOINT_PROFILE.'/'.$profile->id, [
                'alias' => 'LANDING',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['alias']);
        $this->assertSame('current-alias', $profile->fresh()->alias);
    }

    public function test_user_can_not_update_profile_with_empty_description()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'alias' => 'description-required-profile',
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->json('PATCH', self::ENDPOINT_PROFILE.'/'.$profile->id, [
                'alias' => 'description-required-profile',
                'description' => '   ',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['description']);
        $this->assertNotSame('', $profile->fresh()->description);
    }

    public function test_user_can_not_update_profile_with_invalid_status()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->json('PATCH', self::ENDPOINT_PROFILE.'/'.$profile->id, ['status' => 'archived']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_user_can_not_update_profile_publication_state_through_patch()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->json('PATCH', self::ENDPOINT_PROFILE.'/'.$profile->id, [
                'active' => true,
                'status' => ProfileStatus::Published->value,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['active', 'status']);
    }

    public function test_user_can_not_activate_profile_without_required_publication_data()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->json('POST', self::ENDPOINT_PROFILE.'/'.$profile->id.'/activate');

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Profile cannot be activated because required information is missing.');

        $missing = $response->json('data.publication.missing');

        $this->assertContains('alias', $missing);
        $this->assertContains('avatar', $missing);
        $this->assertNotContains('voice', $missing);
        $this->assertContains('source', $missing);
        $this->assertFalse((bool) $profile->fresh()->active);
    }

    public function test_user_can_activate_profile_when_required_publication_data_exists()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $this->createActiveSubscriptionFor($user);
        $profile = $this->createPublishableProfile($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->json('POST', self::ENDPOINT_PROFILE.'/'.$profile->id.'/activate');

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Profile activated successfully.');
        $response->assertJsonPath('data.active', true);
        $response->assertJsonPath('data.status', ProfileStatus::Published->value);
        $response->assertJsonPath('data.publication.can_activate', true);
        $response->assertJsonPath('data.publication.is_published', true);

        $profile->refresh();
        $this->assertTrue((bool) $profile->active);
        $this->assertSame(ProfileStatus::Published, $profile->status);
    }

    public function test_user_can_not_activate_publishable_profile_without_active_subscription(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = $this->createPublishableProfile($user);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->postJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/activate');

        $response->assertStatus(402);
        $response->assertJsonPath('message', 'Active subscription not found.');
        $response->assertJsonPath('errors.subscription.0', 'Active subscription not found.');
        $this->assertFalse((bool) $profile->fresh()->active);
        $this->assertSame(ProfileStatus::Draft, $profile->fresh()->status);
    }

    public function test_starter_user_must_deactivate_current_profile_before_activating_another(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $this->createActiveSubscriptionFor($user);
        $currentProfile = $this->createPublishableProfile($user);
        $nextProfile = $this->createPublishableProfile($user);
        $currentProfile->update([
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);
        $token = $this->getToken($user->email, 'test123');

        $blockedResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_PROFILE.'/'.$nextProfile->id.'/activate');

        $blockedResponse->assertStatus(409);
        $blockedResponse->assertJsonPath('message', 'Active profile limit reached.');
        $blockedResponse->assertJsonPath(
            'errors.profiles.0',
            'Deactivate the currently published profile before activating another one.'
        );
        $this->assertTrue((bool) $currentProfile->fresh()->active);
        $this->assertFalse((bool) $nextProfile->fresh()->active);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_PROFILE.'/'.$currentProfile->id.'/deactivate')
            ->assertStatus(200);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson(self::ENDPOINT_PROFILE.'/'.$nextProfile->id.'/activate')
            ->assertStatus(200)
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.status', ProfileStatus::Published->value);

        $this->assertFalse((bool) $currentProfile->fresh()->active);
        $this->assertSame(ProfileStatus::Hidden, $currentProfile->fresh()->status);
        $this->assertTrue((bool) $nextProfile->fresh()->active);
        $this->assertSame(ProfileStatus::Published, $nextProfile->fresh()->status);
    }

    public function test_user_can_not_activate_profile_with_uploaded_source_that_is_not_approved_and_synced()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = $this->createPublishableProfile($user);

        $profile->sources()->update([
            'status' => ProfileSourceStatus::Uploaded->value,
            'approved_at' => null,
            'indexed_at' => null,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->json('POST', self::ENDPOINT_PROFILE.'/'.$profile->id.'/activate');

        $response->assertStatus(422);
        $response->assertJsonPath('data.publication.can_activate', false);
        $this->assertContains('source', $response->json('data.publication.missing'));
        $this->assertFalse((bool) $profile->fresh()->active);
    }

    public function test_user_can_deactivate_published_profile()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = $this->createPublishableProfile($user);
        $profile->update([
            'active' => true,
            'status' => ProfileStatus::Published,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->json('POST', self::ENDPOINT_PROFILE.'/'.$profile->id.'/deactivate');

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Profile deactivated successfully.');
        $response->assertJsonPath('data.active', false);
        $response->assertJsonPath('data.status', ProfileStatus::Hidden->value);
        $response->assertJsonPath('data.publication.can_activate', true);
        $response->assertJsonPath('data.publication.is_published', false);

        $profile->refresh();
        $this->assertFalse((bool) $profile->active);
        $this->assertSame(ProfileStatus::Hidden, $profile->status);
    }

    public function test_unauthorized_user_can_not_update_a_profile_data()
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->faker->word())
            ->json('PUT', self::ENDPOINT_PROFILE.'/100/data', []);

        $response_content = json_decode($response->getContent());

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_can_not_update_profile_data_if_he_is_not_owner()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $new_data = [
            'me' => ['description' => $this->faker->text(200)],
            'work' => [$this->faker->text(100), $this->faker->text(100)],
            'projects' => [$this->faker->text(100), $this->faker->text(100)],
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken())
            ->json('PUT', self::ENDPOINT_PROFILE.'/'.$profile->id.'/data', ['data' => $new_data]);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Profile not found.');
    }

    public function test_user_can_update_profile_data()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $new_data = [
            'me' => ['description' => $this->faker->text(200)],
            'work' => [$this->faker->text(100), $this->faker->text(100)],
            'projects' => [$this->faker->text(100), $this->faker->text(100)],
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->json('PUT', self::ENDPOINT_PROFILE.'/'.$profile->id.'/data', ['data' => $new_data]);

        $response->assertJsonPath('message', 'Profile updated successfully.');
        $response->assertStatus(200);

        $new_profile = Profile::find($profile->id);
        $this->assertEquals($new_data, $new_profile->data);
    }

    public function test_user_can_update_profile_voice_settings(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'data' => ['existing' => 'value'],
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'name' => $this->faker->name,
            'personality' => $this->faker->text(100),
            'user_id' => $user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->patchJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/voice-settings', [
                'voice_autoplay_enabled' => false,
                'voice_enabled' => false,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Profile voice settings updated successfully.');
        $response->assertJsonPath('data.voice_enabled', false);
        $response->assertJsonPath('data.voice_autoplay_enabled', false);

        $profile->refresh();
        $this->assertSame('value', $profile->data['existing']);
        $this->assertFalse($profile->data['voice_enabled']);
        $this->assertFalse($profile->data['voice_autoplay_enabled']);
    }

    public function test_profile_voice_settings_requires_booleans(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'name' => $this->faker->name,
            'personality' => $this->faker->text(100),
            'user_id' => $user->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->patchJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/voice-settings', [
                'voice_autoplay_enabled' => 'maybe',
                'voice_enabled' => 'maybe',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['voice_autoplay_enabled', 'voice_enabled']);
    }

    public function test_user_can_not_enable_profile_voice_without_a_completed_clone(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::factory()->create(['user_id' => $user->id]);

        Voice::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source' => null,
            'source_voice_id' => null,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->patchJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/voice-settings', [
                'voice_autoplay_enabled' => true,
                'voice_enabled' => true,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['voice_enabled']);
        $this->assertFalse((bool) ($profile->fresh()->data['voice_enabled'] ?? false));
    }

    public function test_user_can_enable_profile_voice_with_a_completed_clone(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::factory()->create(['user_id' => $user->id]);

        Voice::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source' => 'elevenlabs',
            'source_voice_id' => 'configured-provider-voice',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->patchJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/voice-settings', [
                'voice_autoplay_enabled' => true,
                'voice_enabled' => true,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.voice_enabled', true);
        $response->assertJsonPath('data.voice_autoplay_enabled', true);
    }

    public function test_user_without_profile_write_ability_can_not_update_profile_voice_settings(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::create([
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'name' => $this->faker->name,
            'personality' => $this->faker->text(100),
            'user_id' => $user->id,
        ]);
        $token = $user->createToken('test-token', ['profile:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/voice-settings', [
                'voice_autoplay_enabled' => true,
                'voice_enabled' => true,
            ]);

        $response->assertStatus(403);
    }

    public function test_user_can_not_update_profile_voice_settings_if_he_is_not_owner(): void
    {
        $owner = User::factory()->create(['role' => 'user']);
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::create([
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'name' => $this->faker->name,
            'personality' => $this->faker->text(100),
            'user_id' => $owner->id,
        ]);
        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson(self::ENDPOINT_PROFILE.'/'.$profile->id.'/voice-settings', [
                'voice_autoplay_enabled' => true,
                'voice_enabled' => true,
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Profile not found.');
    }

    public function test_social_networks_config_contains_supported_networks_with_s3_icons()
    {
        $networks = config('social-networks.networks');
        $expectedNetworks = [
            'facebook',
            'instagram',
            'tiktok',
            'youtube',
            'linkedin',
            'github',
            'x',
            'threads',
            'whatsapp',
            'telegram',
            'discord',
            'twitch',
            'kick',
            'spotify',
            'onlyfans',
        ];

        $this->assertSame($expectedNetworks, array_keys($networks));

        foreach ($expectedNetworks as $network) {
            $this->assertNotEmpty($networks[$network]['name']);
            $this->assertSame(
                "https://bigmelo-prod-profiles-139194331469.s3.amazonaws.com/icons/{$network}.png",
                $networks[$network]['icon']
            );
        }
    }

    public function test_user_can_update_profile_networks()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $networks = [
            'facebook' => 'https://facebook.com/voitity',
            'instagram' => 'https://instagram.com/voitity',
            'youtube' => 'https://youtube.com/@voitity',
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->json('PUT', self::ENDPOINT_PROFILE.'/'.$profile->id.'/data/networks', [
                'networks' => $networks,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Profile updated successfully.');
        $response->assertJsonPath('data.networks.facebook', $networks['facebook']);
        $response->assertJsonPath('data.networks.instagram', $networks['instagram']);
        $response->assertJsonPath('data.networks.youtube', $networks['youtube']);

        $newProfile = Profile::find($profile->id);
        $this->assertSame($networks, $newProfile->networks);
    }

    public function test_user_can_replace_profile_networks_with_empty_object()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
            'networks' => [
                'facebook' => 'https://facebook.com/voitity',
            ],
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->json('PUT', self::ENDPOINT_PROFILE.'/'.$profile->id.'/data/networks', [
                'networks' => [],
            ]);

        $response->assertStatus(200);
        $this->assertStringContainsString('"networks":{}', $response->getContent());

        $newProfile = Profile::find($profile->id);
        $this->assertSame([], $newProfile->networks);
    }

    public function test_user_can_not_update_profile_networks_with_unsupported_network()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->json('PUT', self::ENDPOINT_PROFILE.'/'.$profile->id.'/data/networks', [
                'networks' => [
                    'myspace' => 'https://myspace.com/voitity',
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['networks.myspace']);
    }

    public function test_user_can_not_update_profile_networks_with_invalid_url()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken($user->email, 'test123'))
            ->json('PUT', self::ENDPOINT_PROFILE.'/'.$profile->id.'/data/networks', [
                'networks' => [
                    'facebook' => 'not-a-url',
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['networks.facebook']);
    }

    public function test_user_without_profile_write_ability_can_not_update_profile_networks()
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);
        $token = $user->createToken('test-token', ['profile:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->json('PUT', self::ENDPOINT_PROFILE.'/'.$profile->id.'/data/networks', [
                'networks' => [
                    'facebook' => 'https://facebook.com/voitity',
                ],
            ]);

        $response->assertStatus(403);
    }

    public function test_user_can_not_update_profile_networks_if_he_is_not_owner()
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id' => $owner->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken())
            ->json('PUT', self::ENDPOINT_PROFILE.'/'.$profile->id.'/data/networks', [
                'networks' => [
                    'facebook' => 'https://facebook.com/voitity',
                ],
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Profile not found.');
    }

    private function createPublishableProfile(User $user): Profile
    {
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'alias' => 'publishable-'.$this->faker->unique()->slug(2),
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
            'active' => false,
            'status' => ProfileStatus::Draft,
        ]);

        ProfileAvatar::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'file' => 'aivideos/profile-avatar.mp4',
            'status' => ProfileAvatar::STATUS_ACTIVE,
        ]);

        ProfileSource::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'type' => 'manual',
            'name' => 'Profile source',
            'status' => ProfileSourceStatus::Indexed->value,
            'approved_at' => now(),
            'indexed_at' => now(),
        ]);

        return $profile;
    }

    private function createActiveSubscriptionFor(User $user): Subscription
    {
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan' => SubscriptionPlan::Starter,
            'started_at' => now()->subDay(),
            'renews_at' => now()->addMonth(),
            'status' => SubscriptionStatus::First,
            'active' => true,
        ]);

        SubscriptionLimit::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'period_started_at' => $subscription->started_at,
            'period_renews_at' => $subscription->renews_at,
            'profiles_remaining' => 1,
            'avatar_images_remaining' => 1,
            'avatar_video_seconds_remaining' => 5,
            'voice_clones_remaining' => 1,
            'tts_characters_remaining' => 10000,
            'chat_messages_remaining' => 1000,
            'credits_remaining' => 1000,
        ]);

        return $subscription;
    }
}
