<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;

class TestControllerTest extends TestAPI
{
    public function test_index_returns_success()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken())->getJson('/api/test');
        $response->assertStatus(200)
                 ->assertJson([
                     'message' => 'API v1 test endpoint working!'
                 ]);
    }
}
