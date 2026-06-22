<?php

use Database\Seeders\LocalTestUserSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('dev:create-test-user {email} {password}', function (string $email, string $password): int {
    try {
        $user = LocalTestUserSeeder::createUser($email, $password);
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());

        return Command::FAILURE;
    }

    $this->info("Local test user ready: {$user->email}");

    return Command::SUCCESS;
})->purpose('Create or update a local test user with the user role');
