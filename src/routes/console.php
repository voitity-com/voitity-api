<?php

use App\Classes\Subscriptions\SubscriptionLimitPeriodService;
use App\Classes\Subscriptions\SubscriptionRenewalService;
use App\Jobs\Subscriptions\BillDueRecurringSubscriptions;
use Database\Seeders\LocalTestUserSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
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

Artisan::command('subscriptions:renew-free', function (SubscriptionRenewalService $renewalService): int {
    $renewed = $renewalService->renewDueFreeSubscriptions();

    $this->info("Free subscriptions renewed: {$renewed}");

    return Command::SUCCESS;
})->purpose('Renew due free recurring subscriptions such as admin grants');

Artisan::command('subscriptions:reset-usage-limits', function (SubscriptionLimitPeriodService $limitPeriods): int {
    $reset = $limitPeriods->resetDueLimitPeriods();

    $this->info("Subscription usage limit periods reset: {$reset}");

    return Command::SUCCESS;
})->purpose('Reset due monthly usage limits for active subscriptions');

Artisan::command('subscriptions:bill-recurring', function (): int {
    $summary = app()->call([new BillDueRecurringSubscriptions, 'handle']);

    $this->info(sprintf(
        'Recurring billing processed: %d. Approved: %d. Pending: %d. Failed: %d. Skipped: %d.',
        $summary['processed'],
        $summary['approved'],
        $summary['pending'],
        $summary['failed'],
        $summary['skipped'],
    ));

    return Command::SUCCESS;
})->purpose('Bill due paid recurring subscriptions through saved payment sources');

Schedule::command('subscriptions:bill-recurring')->dailyAt('00:00');
Schedule::command('subscriptions:renew-free')->dailyAt('00:05');
Schedule::command('subscriptions:reset-usage-limits')->dailyAt('00:10');
