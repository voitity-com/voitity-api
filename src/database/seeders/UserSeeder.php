<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        try {
            $user = User::find(1);

            if (!$user) {
                $user = User::create([
                    'role'              => 'admin',
                    'name'              => 'Admin',
                    'email'             => 'voitity@gmail.com',
                    'password'          => Hash::make('qwerty123'),
                ]);
            }

        } catch (\Throwable $e) {
            Log::info(get_class() . $e->getMessage());
        }
    }
}
