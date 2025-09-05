<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use Faker\Factory;
use Illuminate\Support\Facades\Artisan;
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
        // Run migrations and seeders for sqlite in-memory
        Artisan::call('migrate:fresh');
        Artisan::call('db:seed', ['--class' => 'DatabaseSeeder']);

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
        $response = $this->json('post', '/api/auth/get-token', [
            'email' => $email,
            'password' => $password,
        ], ['Accept' => 'application/json']);

        $response_content = json_decode($response->getContent());

        return $response_content->access_token;
    }
}
