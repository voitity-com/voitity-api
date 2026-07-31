<?php

namespace Tests\Unit\Jobs\Payments;

use App\Classes\PaymentService\PaymentOperationsMonitor;
use App\Jobs\Payments\RecordPaymentQueueHeartbeat;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RecordPaymentQueueHeartbeatTest extends TestCase
{
    #[Test]
    public function it_records_that_the_payment_queue_is_processing_jobs(): void
    {
        Cache::clear();
        Carbon::setTestNow('2026-07-30 12:00:00');

        (new RecordPaymentQueueHeartbeat)->handle(app(PaymentOperationsMonitor::class));

        $this->assertTrue(app(PaymentOperationsMonitor::class)->status()['queue']['healthy']);

        Carbon::setTestNow();
        Cache::clear();
    }
}
