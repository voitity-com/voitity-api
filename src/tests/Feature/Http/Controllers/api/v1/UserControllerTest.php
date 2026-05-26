<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class UserControllerTest extends TestAPI
{
    const ENDPOINT_USER = '/api/user';

    public function test_unauthorized_user_can_not_show_logged_user(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->faker->word())
            ->json('GET', self::ENDPOINT_USER);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_without_user_read_ability_can_not_show_logged_user(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
        ]);
        $token = $user->createToken('test-token', ['profile:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->json('GET', self::ENDPOINT_USER);

        $response->assertStatus(403);
    }

    public function test_user_can_show_logged_user(): void
    {
        $user = User::factory()->create([
            'role' => 'admin',
            'password' => Hash::make('test123'),
            'first_name' => 'Abel',
            'last_name' => 'Moreno',
            'provider' => 'email',
            'avatar' => 'https://example.com/avatar.jpg',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken($user->email, 'test123'))
            ->json('GET', self::ENDPOINT_USER);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'User retrieved successfully.');
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.name', $user->name);
        $response->assertJsonPath('data.first_name', 'Abel');
        $response->assertJsonPath('data.last_name', 'Moreno');
        $response->assertJsonPath('data.email', $user->email);
        $response->assertJsonPath('data.role', 'admin');
        $response->assertJsonPath('data.provider', 'email');
        $response->assertJsonMissing(['password' => $user->password]);
        $response->assertJsonMissing(['remember_token' => $user->remember_token]);
    }
}
