<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class TestAPI extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();
        // Run migrations and seeders for sqlite in-memory
        Artisan::call('migrate:fresh');
        Artisan::call('db:seed', ['--class' => 'DatabaseSeeder']);
    }
}
