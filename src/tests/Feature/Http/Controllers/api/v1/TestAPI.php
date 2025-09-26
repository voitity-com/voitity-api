<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use Faker\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TestAPI extends TestCase
{
    /**
     * Faker object
     *
     * @var \Faker\Generator
     */
    protected $faker;

    /**
     * Set Up for endpoints tests
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->faker = Factory::create();
    }

    /**
     * Get api token
     *
     * @param string $email
     * @param string $password
     *
     * @return string
     */
    protected function getToken(string $email = 'voitity@gmail.com', string $password = 'qwerty123'): string
    {
        // First create an admin user if it doesn't exist
        \App\Models\User::firstOrCreate([
            'email' => $email,
        ], [
            'name' => 'Test Admin User',
            'password' => bcrypt($password),
            'role' => 'admin',
        ]);

        $response = $this->json('post', '/api/auth/get-token', [
            'email' => $email,
            'password' => $password,
        ], ['Accept' => 'application/json']);

        $response_content = json_decode($response->getContent());

        // Debug response if access_token is missing
        if (!isset($response_content->access_token)) {
            throw new \Exception('Authentication failed. Response: ' . $response->getContent());
        }

        return $response_content->access_token;
    }
}
