<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Classes\PaymentService\PaymentOperationsMonitor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentOperationsHealthControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::clear();
        Carbon::setTestNow('2026-07-30 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Cache::clear();

        parent::tearDown();
    }

    #[Test]
    public function it_reports_healthy_when_scheduler_and_queue_heartbeats_are_fresh(): void
    {
        $monitor = app(PaymentOperationsMonitor::class);
        $monitor->recordSchedulerHeartbeat();
        $monitor->recordQueueHeartbeat();
        $monitor->recordValidWebhook();

        $this->getJson('/api/health/payments')
            ->assertOk()
            ->assertJsonPath('data.healthy', true)
            ->assertJsonPath('data.scheduler.healthy', true)
            ->assertJsonPath('data.queue.healthy', true)
            ->assertJsonPath('data.webhook.last_valid_at', '2026-07-30T12:00:00+00:00');
    }

    #[Test]
    public function it_reports_service_unavailable_before_the_workers_have_reported(): void
    {
        $this->getJson('/api/health/payments')
            ->assertStatus(503)
            ->assertJsonPath('data.healthy', false)
            ->assertJsonPath('data.scheduler.healthy', false)
            ->assertJsonPath('data.queue.healthy', false)
            ->assertJsonPath('data.webhook.last_valid_at', null);
    }

    #[Test]
    public function it_reports_service_unavailable_when_the_queue_heartbeat_is_stale(): void
    {
        $monitor = app(PaymentOperationsMonitor::class);
        $monitor->recordSchedulerHeartbeat();
        $monitor->recordQueueHeartbeat();

        Carbon::setTestNow('2026-07-30 12:06:00');
        $monitor->recordSchedulerHeartbeat();

        $this->getJson('/api/health/payments')
            ->assertStatus(503)
            ->assertJsonPath('data.scheduler.healthy', true)
            ->assertJsonPath('data.queue.healthy', false);
    }
}
