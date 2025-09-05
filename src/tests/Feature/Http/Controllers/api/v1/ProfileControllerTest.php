<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Models\Profile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class ProfileControllerTest extends TestAPI
{
    use RefreshDatabase, WithFaker;

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

}
