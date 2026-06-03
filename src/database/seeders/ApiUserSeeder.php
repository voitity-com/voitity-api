<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ApiUserSeeder extends Seeder
{
    public function run(): void
    {
        try {
            $email = env('API_USER_EMAIL', 'web-app@voitity.internal');
            $name = env('API_USER_NAME', 'Web App API');
            $password = env('API_USER_PASSWORD');

            $user = User::firstOrNew(['email' => $email]);
            $user->role = 'api';
            $user->name = $name;

            if (!$user->exists || filled($password)) {
                $user->password = Hash::make($password ?: Str::random(64));
            }

            if (!$user->email_verified_at) {
                $user->email_verified_at = now();
            }

            $user->save();
        } catch (\Throwable $e) {
            Log::error('Error seeding API user.', [
                'message' => $e->getMessage(),
            ]);
        }
    }
}
