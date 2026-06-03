<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Models\Profile;
use App\Models\User;
use App\Models\Voice;
use Illuminate\Support\Facades\Hash;

class ProfileControllerTest extends TestAPI
{

    /**
     * Profile api endpoint
     */
    const ENDPOINT_PROFILE = '/api/profile';

    public function test_store_fails_with_invalid_fields()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken())
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
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->faker->word())
            ->json('POST', self::ENDPOINT_PROFILE, []);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_Can_create_a_profile()
    {
        $profile_data = [
            'name'          => $this->faker->name,
            'alias'         => 'Demo Alias',
            'description'   => $this->faker->text(200),
            'genre'         => 'male',
            'personality'   => $this->faker->text(100)
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken())
            ->postJson(self::ENDPOINT_PROFILE, $profile_data);

        $response->assertJsonPath('message', 'Profile created successfully.');
        $response->assertStatus(200);

        $response_content = json_decode($response->getContent());

        $new_profile = Profile::find($response_content->data->id);
        $this->assertEquals($profile_data['name'], $new_profile->name);
        $this->assertEquals($profile_data['alias'], $new_profile->alias);
        $this->assertEquals($profile_data['description'], $new_profile->description);
        $this->assertTrue((boolean)$new_profile->active);
        $response->assertJsonPath('data.alias', $profile_data['alias']);
    }

    public function test_unauthorized_user_can_not_list_profiles()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->faker->word())
            ->json('GET', self::ENDPOINT_PROFILE);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_without_profile_read_ability_can_not_list_profiles()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->json('GET', self::ENDPOINT_PROFILE);

        $response->assertStatus(403);
    }

    public function test_user_can_list_only_his_profiles()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $otherUser = User::factory()->create(['role' => 'admin']);

        $profileA = Profile::create([
            'user_id'       => $user->id,
            'alias'         => 'Profile A',
            'name'          => $this->faker->name,
            'description'   => $this->faker->text(200),
            'genre'         => 'male',
            'personality'   => $this->faker->text(100)
        ]);
        $profileB = Profile::create([
            'user_id'       => $user->id,
            'alias'         => 'Profile B',
            'name'          => $this->faker->name,
            'description'   => $this->faker->text(200),
            'genre'         => 'female',
            'personality'   => $this->faker->text(100)
        ]);
        $otherProfile = Profile::create([
            'user_id'       => $otherUser->id,
            'name'          => $this->faker->name,
            'description'   => $this->faker->text(200),
            'genre'         => 'male',
            'personality'   => $this->faker->text(100)
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

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken($user->email, 'test123'))
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
        $this->assertTrue($profilesById[$profileA->id]['voice']);
        $this->assertFalse($profilesById[$profileB->id]['voice']);
    }

    public function test_unauthorized_user_can_not_show_a_profile()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->faker->word())
            ->json('GET', self::ENDPOINT_PROFILE . '/100');

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_can_not_show_profile_if_he_is_not_owner()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id'       => $user->id,
            'name'          => $this->faker->name,
            'description'   => $this->faker->text(200),
            'genre'         => 'male',
            'personality'   => $this->faker->text(100)
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken())
            ->json('GET', self::ENDPOINT_PROFILE . '/' . $profile->id);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Profile not found.');
    }

    public function test_user_can_show_profile()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id'       => $user->id,
            'alias'         => 'Show Alias',
            'name'          => $this->faker->name,
            'description'   => $this->faker->text(200),
            'genre'         => 'male',
            'personality'   => $this->faker->text(100)
        ]);
        Voice::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source_voice_id' => 'provider-voice-id',
            'source' => 'elevenlabs',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken($user->email, 'test123'))
            ->json('GET', self::ENDPOINT_PROFILE . '/' . $profile->id);

        $response->assertJsonPath('message', 'Profile retrieved successfully.');
        $response->assertJsonPath('data.id', $profile->id);
        $response->assertJsonPath('data.name', $profile->name);
        $response->assertJsonPath('data.alias', $profile->alias);
        $response->assertJsonPath('data.description', $profile->description);
        $response->assertJsonPath('data.voice', true);
        $response->assertStatus(200);
    }

    public function test_user_can_show_profile_by_alias_without_owner_validation()
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $reader = User::factory()->create(['role' => 'api']);
        $profile = Profile::create([
            'user_id'       => $owner->id,
            'alias'         => 'public-alias',
            'name'          => $this->faker->name,
            'description'   => $this->faker->text(200),
            'genre'         => 'male',
            'personality'   => $this->faker->text(100)
        ]);
        Voice::factory()->create([
            'user_id' => $owner->id,
            'profile_id' => $profile->id,
            'source_voice_id' => 'provider-voice-id',
            'source' => 'elevenlabs',
        ]);

        $token = $reader->createToken('test-token', ['profile:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->json('GET', self::ENDPOINT_PROFILE . '/alias/' . $profile->alias);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Profile retrieved successfully.');
        $response->assertJsonPath('data.id', $profile->id);
        $response->assertJsonPath('data.user_id', $owner->id);
        $response->assertJsonPath('data.alias', $profile->alias);
        $response->assertJsonPath('data.voice', true);
    }

    public function test_user_without_profile_read_ability_can_not_show_profile_by_alias()
    {
        $user = User::factory()->create(['role' => 'api']);
        $profile = Profile::create([
            'user_id'       => $user->id,
            'alias'         => 'private-alias',
            'name'          => $this->faker->name,
            'description'   => $this->faker->text(200),
            'genre'         => 'male',
            'personality'   => $this->faker->text(100)
        ]);

        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->json('GET', self::ENDPOINT_PROFILE . '/alias/' . $profile->alias);

        $response->assertStatus(403);
    }

    public function test_unauthorized_user_can_not_update_a_profile()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->faker->word())
            ->json('PATCH', self::ENDPOINT_PROFILE . '/100', []);

        $response_content = json_decode($response->getContent());

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_can_not_update_profile_if_he_is_not_owner()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id'       => $user->id,
            'name'          => $this->faker->name,
            'description'   => $this->faker->text(200),
            'genre'         => 'male',
            'personality'   => $this->faker->text(100)
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken())
            ->json('PATCH', self::ENDPOINT_PROFILE . '/' . $profile->id, []);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Profile not found.');
    }

    public function test_user_can_update_profile()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id'       => $user->id,
            'name'          => $this->faker->name,
            'description'   => $this->faker->text(200),
            'genre'         => 'male',
            'personality'   => $this->faker->text(100)
        ]);

        $new_data = [
            'name'          => $this->faker->name,
            'alias'         => 'Updated Alias',
            'description'   => $this->faker->text(200),
            'genre'         => 'female',
            'active'        => false
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken($user->email, 'test123'))
            ->json('PATCH', self::ENDPOINT_PROFILE . '/' . $profile->id, $new_data);

        $response->assertJsonPath('message', 'Profile updated successfully.');
        $response->assertStatus(200);

        $new_profile = Profile::find($profile->id);
        $this->assertEquals($new_data['name'], $new_profile->name);
        $this->assertEquals($new_data['alias'], $new_profile->alias);
        $this->assertEquals($new_data['description'], $new_profile->description);
        $this->assertEquals($new_data['genre'], $new_profile->genre);
        $this->assertFalse((boolean)$new_profile->active);
        $response->assertJsonPath('data.alias', $new_data['alias']);
    }

    public function test_unauthorized_user_can_not_update_a_profile_data()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->faker->word())
            ->json('PUT', self::ENDPOINT_PROFILE . '/100/data', []);

        $response_content = json_decode($response->getContent());

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_can_not_update_profile_data_if_he_is_not_owner()
    {
        $user = User::factory()->create(['role' => 'admin']);
        $profile = Profile::create([
            'user_id'       => $user->id,
            'name'          => $this->faker->name,
            'description'   => $this->faker->text(200),
            'genre'         => 'male',
            'personality'   => $this->faker->text(100)
        ]);

        $new_data = [
            'me'            => ['description' => $this->faker->text(200)],
            'work'          => [$this->faker->text(100), $this->faker->text(100)],
            'projects'      => [$this->faker->text(100), $this->faker->text(100)],
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken())
            ->json('PUT', self::ENDPOINT_PROFILE . '/' . $profile->id . '/data', ['data' => $new_data]);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Profile not found.');
    }

    public function test_user_can_update_profile_data()
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => Hash::make('test123')]);
        $profile = Profile::create([
            'user_id'       => $user->id,
            'name'          => $this->faker->name,
            'description'   => $this->faker->text(200),
            'genre'         => 'male',
            'personality'   => $this->faker->text(100)
        ]);

        $new_data = [
            'me'            => ['description' => $this->faker->text(200)],
            'work'          => [$this->faker->text(100), $this->faker->text(100)],
            'projects'      => [$this->faker->text(100), $this->faker->text(100)],
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken($user->email, 'test123'))
            ->json('PUT', self::ENDPOINT_PROFILE . '/' . $profile->id . '/data', ['data' => $new_data]);

        $response->assertJsonPath('message', 'Profile updated successfully.');
        $response->assertStatus(200);

        $new_profile = Profile::find($profile->id);
        $this->assertEquals($new_data, $new_profile->data);
    }

}
