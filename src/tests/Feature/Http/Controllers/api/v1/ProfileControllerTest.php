<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Models\Profile;
use App\Models\User;
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
                // 'description' => missing
                'genre' => 'toolongforgenre', // too long
                // 'personality' => missing
            ]);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'description', 'genre', 'personality']);
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
        $this->assertEquals($profile_data['description'], $new_profile->description);
        $this->assertTrue((boolean)$new_profile->active);
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
            'name'          => $this->faker->name,
            'description'   => $this->faker->text(200),
            'genre'         => 'male',
            'personality'   => $this->faker->text(100)
        ]);
        $profileB = Profile::create([
            'user_id'       => $user->id,
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
            'name'          => $this->faker->name,
            'description'   => $this->faker->text(200),
            'genre'         => 'male',
            'personality'   => $this->faker->text(100)
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken($user->email, 'test123'))
            ->json('GET', self::ENDPOINT_PROFILE . '/' . $profile->id);

        $response->assertJsonPath('message', 'Profile retrieved successfully.');
        $response->assertJsonPath('data.id', $profile->id);
        $response->assertJsonPath('data.name', $profile->name);
        $response->assertJsonPath('data.description', $profile->description);
        $response->assertStatus(200);
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
        $this->assertEquals($new_data['description'], $new_profile->description);
        $this->assertEquals($new_data['genre'], $new_profile->genre);
        $this->assertFalse((boolean)$new_profile->active);
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
