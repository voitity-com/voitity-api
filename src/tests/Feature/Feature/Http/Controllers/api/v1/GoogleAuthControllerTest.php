<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Models\User;
use App\Services\GoogleOAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAuthControllerTest extends TestCase
{
    use RefreshDatabase;

    private const GOOGLE_USER_INFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    public function test_google_auth_creates_new_user_successfully()
    {
        // Mock Google API response
        Http::fake([
            self::GOOGLE_USER_INFO_URL => Http::response([
                'id' => '123456789012345678901',
                'email' => 'test@gmail.com',
                'name' => 'Test User',
                'picture' => 'https://lh3.googleusercontent.com/test.jpg',
                'verified_email' => true
            ])
        ]);

        $response = $this->postJson('/api/auth/google', [
            'google_id' => '123456789012345678901',
            'email' => 'test@gmail.com',
            'name' => 'Test User',
            'avatar' => 'https://lh3.googleusercontent.com/test.jpg',
            'access_token' => 'mock_google_token'
        ]);

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'access_token',
                    'user' => [
                        'id',
                        'name',
                        'email',
                        'avatar',
                        'provider'
                    ]
                ]);

        $this->assertDatabaseHas('users', [
            'email' => 'test@gmail.com',
            'google_id' => '123456789012345678901',
            'provider' => 'google'
        ]);
    }

    public function test_google_auth_links_existing_user_by_email()
    {
        // Create existing user with same email
        $existingUser = User::create([
            'name' => 'Existing User',
            'email' => 'test@gmail.com',
            'password' => bcrypt('password'),
            'role' => 'user'
        ]);

        // Mock Google API response
        Http::fake([
            self::GOOGLE_USER_INFO_URL => Http::response([
                'id' => '123456789012345678901',
                'email' => 'test@gmail.com',
                'name' => 'Test User Google',
                'picture' => 'https://lh3.googleusercontent.com/test.jpg',
                'verified_email' => true
            ])
        ]);

        $response = $this->postJson('/api/auth/google', [
            'google_id' => '123456789012345678901',
            'email' => 'test@gmail.com',
            'name' => 'Test User Google',
            'avatar' => 'https://lh3.googleusercontent.com/test.jpg',
            'access_token' => 'mock_google_token'
        ]);

        $response->assertStatus(200);

        // Verify user was updated with Google info
        $existingUser->refresh();
        $this->assertEquals('123456789012345678901', $existingUser->google_id);
        $this->assertEquals('google', $existingUser->provider);
        $this->assertNotNull($existingUser->google_verified_at);
    }

    public function test_google_auth_fails_with_invalid_token()
    {
        // Mock Google API failure
        Http::fake([
            self::GOOGLE_USER_INFO_URL => Http::response([], 401)
        ]);

        $response = $this->postJson('/api/auth/google', [
            'google_id' => '123456789012345678901',
            'email' => 'test@gmail.com',
            'name' => 'Test User',
            'access_token' => 'invalid_google_token'
        ]);

        $response->assertStatus(401)
                ->assertJson([
                    'message' => 'Invalid Google access token.'
                ]);
    }

    public function test_google_auth_fails_with_mismatched_google_id()
    {
        // Mock Google API with different ID
        Http::fake([
            self::GOOGLE_USER_INFO_URL => Http::response([
                'id' => '999999999999999999999',
                'email' => 'test@gmail.com',
                'name' => 'Test User',
                'verified_email' => true
            ])
        ]);

        $response = $this->postJson('/api/auth/google', [
            'google_id' => '123456789012345678901',
            'email' => 'test@gmail.com',
            'name' => 'Test User',
            'access_token' => 'mock_google_token'
        ]);

        $response->assertStatus(401)
                ->assertJson([
                    'message' => 'Google ID mismatch.'
                ]);
    }

    public function test_google_auth_validation_fails_with_missing_fields()
    {
        $response = $this->postJson('/api/auth/google', [
            'email' => 'test@gmail.com',
            // Missing required fields
        ]);

        $response->assertStatus(422)
                ->assertJsonValidationErrors(['google_id', 'name', 'access_token']);
    }

    public function test_logout_revokes_tokens_successfully()
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token->plainTextToken)
                        ->postJson('/api/auth/logout');

        $response->assertStatus(200)
                ->assertJson([
                    'message' => 'Successfully logged out.'
                ]);

        // Verify token was revoked
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'test-token'
        ]);
    }
}ts\Feature\Feature\Http\Controllers\api\v1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class GoogleAuthControllerTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    public function test_example(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }
}
