<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class SubscriptionScheduleConfigurationTest extends TestCase
{
    public function test_subscription_and_notification_schedules_are_single_server_and_non_overlapping(): void
    {
        $commands = [
            'subscriptions:bill-recurring',
            'subscriptions:expire-ended',
            'subscriptions:renew-free',
            'subscriptions:reset-usage-limits',
            'subscriptions:release-stale-usage-reservations',
            'notifications:subscription-renewal-reminders',
            'notifications:monthly-usage-summary',
        ];
        $events = collect(app(Schedule::class)->events());

        foreach ($commands as $command) {
            $event = $events->first(
                fn ($event): bool => str_contains((string) $event->command, $command)
            );

            $this->assertNotNull($event, "Scheduled command [{$command}] was not registered.");
            $this->assertTrue($event->withoutOverlapping, "Scheduled command [{$command}] may overlap.");
            $this->assertTrue($event->onOneServer, "Scheduled command [{$command}] may run on multiple servers.");
        }
    }

    public function test_database_queue_retry_window_exceeds_the_worker_timeout(): void
    {
        $workerTimeout = 300;

        $this->assertGreaterThan(
            $workerTimeout,
            (int) config('queue.connections.database.retry_after')
        );
        $this->assertTrue((bool) config('queue.connections.database.after_commit'));
    }
}
