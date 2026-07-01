<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Enums\ProfileStatus;
use App\Enums\SubscriptionUsageType;
use App\Events\Subscriptions\SubscriptionUsageRequested;
use App\Models\Profile;
use App\Models\User;
use App\Models\Voice;
use Illuminate\Support\Facades\Event;
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
                // 'personality' => missing
            ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'alias', 'description', 'genre', 'personality']);
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
        Event::fake([SubscriptionUsageRequested::class]);

        $profile_data = [
            'name' => $this->faker->name,
            'alias' => 'Demo Alias',
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ];

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken())
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
        $this->assertTrue((bool) $new_profile->active);
        $this->assertSame(ProfileStatus::Draft, $new_profile->status);
        $this->assertSame($new_profile->user_id, $baseVoice->user_id);
        $this->assertSame($new_profile->name, $baseVoice->name);
        $this->assertSame($new_profile->description, $baseVoice->description);
        $this->assertSame('es', $baseVoice->language_code);
        $this->assertTrue((bool) $baseVoice->active);
        $this->assertNull($baseVoice->source);
        $this->assertNull($baseVoice->source_voice_id);
        $response->assertJsonPath('data.alias', $profile_data['alias']);
        $response->assertJsonPath('data.status', ProfileStatus::Draft->value);
        $response->assertJsonPath('data.voice', false);
        $response->assertJsonPath('data.voice_id', $baseVoice->id);
        Event::assertDispatched(SubscriptionUsageRequested::class, function (SubscriptionUsageRequested $event) use ($new_profile) {
            return $event->usageType === SubscriptionUsageType::ProfileCreated
                && $event->userId === $new_profile->user_id
                && $event->profileId === $new_profile->id
                && $event->amounts === ['profiles' => 1]
                && $event->idempotencyKey === "profile-created:{$new_profile->id}";
        });
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
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->getToken())
            ->json('GET', self::ENDPOINT_PROFILE.'/'.$profile->id);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Profile not found.');
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
            'status' => ProfileStatus::Published,
        ]);
        Voice::factory()->create([
            'user_id' => $owner->id,
            'profile_id' => $profile->id,
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
        $response->assertJsonPath('data.status', ProfileStatus::Published->value);
        $response->assertJsonPath('data.voice', true);
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
            'active' => false,
            'status' => ProfileStatus::Published->value,
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
        $this->assertFalse((bool) $new_profile->active);
        $this->assertSame(ProfileStatus::Published, $new_profile->status);
        $response->assertJsonPath('data.alias', $new_data['alias']);
        $response->assertJsonPath('data.status', ProfileStatus::Published->value);
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
}
