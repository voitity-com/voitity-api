<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class TestControllerTest extends TestCase
{
    public function test_index_returns_success()
    {
        $user = \App\Models\User::first();
        $token = $user->createToken('test-token', ['test:test']);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token->plainTextToken,
            'Accept' => 'application/json',
        ])->get('/api/test');
        $response->assertStatus(200)
                 ->assertJson([
                     'message' => 'API v1 test endpoint working!'
                 ]);
    }
}
