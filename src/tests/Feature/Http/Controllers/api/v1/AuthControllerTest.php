<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthControllerTest extends TestAPI
{

    /**
     * Auth api endpoint
     */
    const ENDPOINT_AUTH = '/api/auth';


    #[Test]
    public function get_access_token_with_email_and_password(): void
    {
        $response = $this->json('post', self::ENDPOINT_AUTH . '/get-token', [
            'email' => 'voitity@gmail.com', // matches UserSeeder
            'password' => 'qwerty123',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(200);
        $response->assertJsonStructure(['access_token']);
    }

    #[Test]
    public function login_error_wrong_credentials(): void
    {
        $response = $this->json('post', self::ENDPOINT_AUTH . '/get-token', [
            'email' => 'wrong_email@mydomain.com',
            'password' => 'wrong_password',
        ], ['Accept' => 'application/json']);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'Your email or password are incorrect.');
    }
}
