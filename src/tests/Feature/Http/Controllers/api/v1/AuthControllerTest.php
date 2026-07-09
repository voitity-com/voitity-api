<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;

class AuthControllerTest extends TestAPI
{
    /**
     * Auth api endpoint
     */
    const ENDPOINT_AUTH = '/api/auth';

    #[Test]
    public function get_access_token_with_email_and_password(): void
    {
        // Create the test user first
        \App\Models\User::create([
            'name' => 'Test Admin User',
            'email' => 'voitity@gmail.com',
            'password' => bcrypt('qwerty123'),
            'role' => 'admin',
        ]);

        $response = $this->json('post', self::ENDPOINT_AUTH.'/get-token', [
            'email' => 'voitity@gmail.com',
            'password' => 'qwerty123',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(200);
        $response->assertJsonStructure(['access_token']);
    }

    #[Test]
    public function login_error_wrong_credentials(): void
    {
        $response = $this->json('post', self::ENDPOINT_AUTH.'/get-token', [
            'email' => 'wrong_email@mydomain.com',
            'password' => 'wrong_password',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Your email or password are incorrect.');
    }

    #[Test]
    public function sign_up_creates_email_user_and_returns_access_token(): void
    {
        $response = $this->postJson(self::ENDPOINT_AUTH.'/sign-up', [
            'name' => 'Abel Moreno',
            'email' => 'Abel.SignUp@example.com',
            'password' => 'Test12345!',
            'password_confirmation' => 'Test12345!',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'access_token',
            'user' => [
                'id',
                'name',
                'email',
                'first_name',
                'last_name',
                'provider',
                'role',
            ],
        ]);
        $response->assertJsonPath('user.name', 'Abel Moreno');
        $response->assertJsonPath('user.first_name', 'Abel');
        $response->assertJsonPath('user.last_name', 'Moreno');
        $response->assertJsonPath('user.email', 'abel.signup@example.com');
        $response->assertJsonPath('user.provider', 'email');
        $response->assertJsonPath('user.role', 'user');

        $user = User::where('email', 'abel.signup@example.com')->firstOrFail();

        $this->assertTrue(Hash::check('Test12345!', $user->password));
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'role' => 'user',
            'provider' => 'email',
            'first_name' => 'Abel',
            'last_name' => 'Moreno',
        ]);
    }

    #[Test]
    public function sign_up_rejects_duplicate_email(): void
    {
        User::factory()->create([
            'email' => 'existing@example.com',
            'role' => 'user',
        ]);

        $response = $this->postJson(self::ENDPOINT_AUTH.'/sign-up', [
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'password' => 'Test12345!',
            'password_confirmation' => 'Test12345!',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['email']);
    }

    #[Test]
    public function sign_up_validates_required_fields_and_password_confirmation(): void
    {
        $response = $this->postJson(self::ENDPOINT_AUTH.'/sign-up', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'different',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'email', 'password']);
    }
}
