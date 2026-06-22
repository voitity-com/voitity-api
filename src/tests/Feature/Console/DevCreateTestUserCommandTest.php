<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DevCreateTestUserCommandTest extends TestCase
{
    #[Test]
    public function it_creates_a_user_role_test_user_when_environment_is_local(): void
    {
        Config::set('app.env', 'local');

        $this->artisan('dev:create-test-user', [
            'email' => 'Example.Test@BigMelo.COM',
            'password' => 'test123',
        ])->assertExitCode(0);

        $user = User::where('email', 'example.test@bigmelo.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('user', $user->role);
        $this->assertSame('Example Test', $user->name);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('test123', $user->password));
    }

    #[Test]
    public function it_refuses_to_create_a_test_user_when_environment_is_not_local(): void
    {
        Config::set('app.env', 'testing');

        $this->artisan('dev:create-test-user', [
            'email' => 'blocked@bigmelo.com',
            'password' => 'test123',
        ])->assertExitCode(1);

        $this->assertDatabaseMissing('users', [
            'email' => 'blocked@bigmelo.com',
        ]);
    }
}
