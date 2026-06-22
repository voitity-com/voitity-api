<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use RuntimeException;

class LocalTestUserSeeder extends Seeder
{
    public const DEFAULT_EMAIL = 'test01@bigmelo.com';

    public const DEFAULT_PASSWORD = 'test123';

    public function run(): void
    {
        self::createUser(self::DEFAULT_EMAIL, self::DEFAULT_PASSWORD);
    }

    public static function createUser(string $email, string $password): User
    {
        self::ensureLocalEnvironment();

        $email = strtolower(trim($email));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('A valid email is required.');
        }

        if ($password === '') {
            throw new InvalidArgumentException('Password is required.');
        }

        $user = User::firstOrNew(['email' => $email]);

        $user->forceFill([
            'role' => 'user',
            'name' => self::nameFromEmail($email),
            'password' => Hash::make($password),
            'email_verified_at' => $user->email_verified_at ?? now(),
        ]);

        $user->save();

        return $user;
    }

    private static function ensureLocalEnvironment(): void
    {
        $environment = (string) config('app.env');

        if ($environment !== 'local') {
            throw new RuntimeException(
                "Local test users can only be created when config app.env is local. Current app.env: {$environment}."
            );
        }
    }

    private static function nameFromEmail(string $email): string
    {
        $localPart = explode('@', $email)[0] ?? 'Test User';

        return str($localPart)
            ->replace(['.', '-', '_'], ' ')
            ->title()
            ->toString();
    }
}
