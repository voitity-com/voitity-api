<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    
    public function setUp(): void
    {
        parent::setUp();

        // Set the database connection to use SQLite in-memory for testing
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite.database', ':memory:');

        DB::disconnect('sqlite');

        // Run the migrations
        Artisan::call('migrate:fresh');
        Artisan::call('db:seed', ['--class' => 'DatabaseSeeder']);
    }
}
