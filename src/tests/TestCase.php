<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    // Don't use RefreshDatabase trait - implement our own database handling
    protected static $migrationsRun = [];
    protected static $processId = null;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {        
        parent::setUp();
        
        // CRITICAL: Abort tests if we're not in testing environment
        if ($this->app->environment() !== 'testing') {
            throw new \RuntimeException('Tests can only run in testing environment to protect production data. Current environment: ' . $this->app->environment());
        }
        
        // Create unique process ID for parallel test isolation
        if (static::$processId === null) {
            static::$processId = getmypid() . '_' . microtime(true);
        }
        
        // Force SQLite configuration for tests - MULTIPLE LAYERS OF PROTECTION
        Config::set('database.default', 'sqlite');
        Config::set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        
        // Disconnect ALL database connections to ensure clean slate
        DB::purge();
        
        // Reconnect to ensure fresh connection
        DB::reconnect();
        
        // CRITICAL VERIFICATION - Multiple checks to ensure we're using SQLite
        $connection = DB::connection();
        $driverName = $connection->getDriverName();
        $defaultConnection = Config::get('database.default');
        $sqliteDatabase = Config::get('database.connections.sqlite.database');
        $actualDatabase = $connection->getDatabaseName();
        
        // Comprehensive assertions to protect production data
        if ($driverName !== 'sqlite') {
            throw new \RuntimeException("CRITICAL ERROR: Tests are using {$driverName} instead of SQLite - ABORTING to protect production data");
        }
        
        if ($defaultConnection !== 'sqlite') {
            throw new \RuntimeException("CRITICAL ERROR: Default connection is {$defaultConnection} instead of SQLite - ABORTING to protect production data");
        }
        
        if ($sqliteDatabase !== ':memory:') {
            throw new \RuntimeException("CRITICAL ERROR: SQLite database is {$sqliteDatabase} instead of :memory: - ABORTING to protect production data");
        }
        
        if (!($actualDatabase === ':memory:' || $actualDatabase === '')) {
            throw new \RuntimeException("CRITICAL ERROR: Actual database is {$actualDatabase} instead of in-memory - ABORTING to protect production data");
        }
        
        // Handle migrations safely with process-specific tracking
        $processKey = static::$processId;
        
        // Always check if database schema is ready, regardless of process tracking
        $schemaReady = $this->isDatabaseSchemaReady();
        
        if (!$schemaReady || !isset(static::$migrationsRun[$processKey])) {
            // Need to run migrations
            try {
                $this->artisan('migrate', ['--force' => true]);
                static::$migrationsRun[$processKey] = true;
            } catch (\Exception $migrationError) {
                $errorMessage = $migrationError->getMessage();
                
                // Handle common parallel execution errors gracefully
                if (str_contains($errorMessage, 'already exists') || 
                    str_contains($errorMessage, 'table "migrations" already exists') ||
                    str_contains($errorMessage, 'cannot VACUUM from within a transaction')) {
                    
                    // Migration table already exists - verify schema is complete
                    if ($this->isDatabaseSchemaReady()) {
                        static::$migrationsRun[$processKey] = true;
                    } else {
                        // Schema incomplete - try again with fresh database
                        $this->resetDatabase();
                        try {
                            $this->artisan('migrate', ['--force' => true]);
                            static::$migrationsRun[$processKey] = true;
                        } catch (\Exception $retryError) {
                            if ($this->isDatabaseSchemaReady()) {
                                static::$migrationsRun[$processKey] = true;
                            } else {
                                throw $retryError;
                            }
                        }
                    }
                } else {
                    // Unexpected error - rethrow
                    throw $migrationError;
                }
            }
        } else {
            // Migrations already run for this process, just clean data
            try {
                $this->cleanTestData();
            } catch (\Exception $cleanupError) {
                // If cleanup fails, just continue - the test will create fresh data anyway
            }
        }
        
        // Final verification that database is properly set up
        $this->verifyTestDatabaseState();
        
        // Log success for debugging (only once per process)
        if (!isset(static::$migrationsRun[$processKey . '_logged'])) {
            echo "\n✅ Database safety verified: Using SQLite in-memory database (Process: " . static::$processId . ")\n";
            static::$migrationsRun[$processKey . '_logged'] = true;
        }
    }

    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        // Set environment variables before creating the app
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = ':memory:';
        $_ENV['APP_ENV'] = 'testing';
        
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        putenv('APP_ENV=testing');
        
        $app = require __DIR__.'/../bootstrap/app.php';
        
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        
        // Force database configuration after bootstrap
        $app['config']['database.default'] = 'sqlite';
        $app['config']['database.connections.sqlite'] = [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ];
        
        return $app;
    }

    /**
     * Verify that the test database is properly configured
     */
    private function verifyTestDatabaseState(): void
    {
        try {
            $connection = DB::connection();
            $driverName = $connection->getDriverName();
            
            if ($driverName !== 'sqlite') {
                throw new \RuntimeException("CRITICAL ERROR: Tests are using {$driverName} instead of SQLite");
            }
            
            // Ensure basic database functionality
            DB::select('SELECT 1 as test');
            
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'CRITICAL ERROR')) {
                // Database connection issue - try to reconnect once
                try {
                    DB::purge();
                    DB::reconnect();
                    DB::select('SELECT 1 as test');
                } catch (\Exception $reconnectError) {
                    throw new \RuntimeException('Failed to establish test database connection: ' . $reconnectError->getMessage());
                }
            } else {
                throw $e;
            }
        }
    }

    /**
     * Check if all required database tables exist
     */
    private function isDatabaseSchemaReady(): bool
    {
        try {
            $requiredTables = [
                'users', 
                'personal_access_tokens', 
                'profiles', 
                'voices', 
                'voice_samples', 
                'voice_provider_requests',
                'migrations'
            ];
            
            $existingTables = collect(DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"))
                ->pluck('name')
                ->toArray();
            
            foreach ($requiredTables as $table) {
                if (!in_array($table, $existingTables)) {
                    return false;
                }
            }
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Reset the database connection
     */
    private function resetDatabase(): void
    {
        DB::purge();
        DB::reconnect();
    }

    /**
     * Clean test data from all tables except migrations
     */
    private function cleanTestData(): void
    {
        $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
        foreach ($tables as $table) {
            if ($table->name !== 'migrations') {
                DB::statement("DELETE FROM `{$table->name}`");
            }
        }
    }

}
