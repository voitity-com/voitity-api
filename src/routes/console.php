<?php

use App\Classes\Subscriptions\SubscriptionLimitPeriodService;
use App\Classes\Subscriptions\SubscriptionRenewalService;
use App\Classes\Subscriptions\SubscriptionTrialService;
use App\Classes\UsdCopRateService\UsdCopRateService;
use App\Jobs\Subscriptions\BillDueRecurringSubscriptions;
use App\Mail\TestMailConfiguration;
use App\Models\ProfileProduct;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Products\ProfileProductImageService;
use Database\Seeders\LocalTestUserSeeder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
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

Artisan::command('mail:test {email} {--queue}', function (string $email): int {
    $mailable = new TestMailConfiguration($email);

    if ($this->option('queue')) {
        Mail::to($email)->queue($mailable);
        $this->info("Test email queued to {$email} using ".config('mail.default').' mailer.');

        return Command::SUCCESS;
    }

    Mail::to($email)->send($mailable);
    $this->info("Test email sent to {$email} using ".config('mail.default').' mailer.');

    return Command::SUCCESS;
})->purpose('Send a branded test email through the configured mailer');

Artisan::command('products:refresh-social-images {--force}', function (ProfileProductImageService $images): int {
    $generated = 0;
    $skipped = 0;

    ProfileProduct::query()
        ->whereNotNull('storage_disk')
        ->whereNotNull('storage_path')
        ->when(! $this->option('force'), fn ($query) => $query->whereNull('social_storage_path'))
        ->orderBy('id')
        ->chunkById(100, function ($products) use (&$generated, &$skipped, $images): void {
            foreach ($products as $product) {
                try {
                    $images->refreshSocialPreview($product) ? $generated++ : $skipped++;
                } catch (Throwable $exception) {
                    $skipped++;
                    $this->warn("Product {$product->id}: {$exception->getMessage()}");
                }
            }
        });

    $this->info("Product social images generated: {$generated}. Skipped: {$skipped}.");

    return Command::SUCCESS;
})->purpose('Generate JPEG social previews for uploaded product images');

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

Artisan::command('subscriptions:bill-recurring', function (UsdCopRateService $usdCopRateService): int {
    $usdCopRateService->syncConfig();

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
})->purpose('Bill due recurring subscriptions and trial conversions through saved payment sources');

Artisan::command('subscriptions:expire-ended', function (SubscriptionTrialService $trialService): int {
    $expired = $trialService->expireEndedSubscriptions();

    $this->info("Ended subscriptions expired: {$expired}");

    return Command::SUCCESS;
})->purpose('Expire subscriptions that were cancelled at period end');

Artisan::command('notifications:subscription-renewal-reminders {--days=7}', function (NotificationDispatcher $dispatcher): int {
    $days = max(1, (int) $this->option('days'));
    $target = now()->addDays($days);
    $start = $target->copy()->startOfDay();
    $end = $target->copy()->endOfDay();
    $sent = 0;

    Subscription::query()
        ->with('user')
        ->where('active', true)
        ->where('billing_mode', 'recurring')
        ->where('cancel_at_period_end', false)
        ->where(function ($query) use ($end, $start): void {
            $query
                ->whereBetween('next_billing_at', [$start, $end])
                ->orWhere(function ($query) use ($end, $start): void {
                    $query
                        ->whereNull('next_billing_at')
                        ->whereBetween('renews_at', [$start, $end]);
                });
        })
        ->orderBy('id')
        ->chunkById(100, function ($subscriptions) use (&$sent, $dispatcher): void {
            foreach ($subscriptions as $subscription) {
                if (! $subscription instanceof Subscription || ! $subscription->user instanceof User) {
                    continue;
                }

                $renewsAt = $subscription->next_billing_at ?? $subscription->renews_at;
                $dispatcher->send($subscription->user, 'subscription_renewal_reminder', [
                    'plan' => $subscription->plan->value,
                    'subscription_id' => $subscription->id,
                    'renews_at' => $renewsAt?->toFormattedDateString(),
                ]);
                $sent++;
            }
        });

    $this->info("Subscription renewal reminders sent: {$sent}");

    return Command::SUCCESS;
})->purpose('Send subscription renewal reminders for subscriptions due soon');

Artisan::command('notifications:monthly-usage-summary', function (NotificationDispatcher $dispatcher): int {
    $sent = 0;
    $period = now()->subMonthNoOverflow()->format('F Y');

    Subscription::query()
        ->with(['user', 'limit'])
        ->where('active', true)
        ->orderBy('id')
        ->chunkById(100, function ($subscriptions) use (&$sent, $dispatcher, $period): void {
            foreach ($subscriptions as $subscription) {
                if (! $subscription instanceof Subscription || ! $subscription->user instanceof User) {
                    continue;
                }

                $dispatcher->send($subscription->user, 'monthly_usage_summary', [
                    'plan' => $subscription->plan->value,
                    'subscription_id' => $subscription->id,
                    'period' => $period,
                ]);
                $sent++;
            }
        });

    $this->info("Monthly usage summaries sent: {$sent}");

    return Command::SUCCESS;
})->purpose('Send monthly usage summary notifications to active subscribers');

Artisan::command('notifications:admin-alert {message}', function (string $message, NotificationDispatcher $dispatcher): int {
    $dispatcher->sendToAdmins('critical_admin_alert', ['message' => $message]);
    $this->info('Critical admin alert sent.');

    return Command::SUCCESS;
})->purpose('Send a critical notification to admin users');

Artisan::command('notifications:integration-error {service} {message}', function (
    string $service,
    string $message,
    NotificationDispatcher $dispatcher
): int {
    $dispatcher->sendToAdmins('external_integration_error', [
        'service' => $service,
        'message' => $message,
    ]);
    $this->info('External integration error notification sent to admins.');

    return Command::SUCCESS;
})->purpose('Send an external integration error notification to admin users');

Artisan::command('notifications:service-notice {message}', function (
    string $message,
    NotificationDispatcher $dispatcher
): int {
    $sent = 0;

    User::query()
        ->whereNotNull('email')
        ->orderBy('id')
        ->chunkById(100, function ($users) use (&$sent, $dispatcher, $message): void {
            foreach ($users as $user) {
                if (! $user instanceof User) {
                    continue;
                }

                $dispatcher->send($user, 'service_maintenance_or_degradation', [
                    'message' => $message,
                ]);
                $sent++;
            }
        });

    $this->info("Service notice sent: {$sent}");

    return Command::SUCCESS;
})->purpose('Send a service maintenance or degradation notice to users');

Schedule::command('subscriptions:bill-recurring')->hourly();
Schedule::command('subscriptions:expire-ended')->hourlyAt(5);
Schedule::command('subscriptions:renew-free')->dailyAt('00:05');
Schedule::command('subscriptions:reset-usage-limits')->dailyAt('00:10');
Schedule::command('notifications:subscription-renewal-reminders')->dailyAt('08:00');
Schedule::command('notifications:monthly-usage-summary')->monthlyOn(1, '08:15');
