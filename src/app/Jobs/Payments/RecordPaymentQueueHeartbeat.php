<?php

namespace App\Jobs\Payments;

use App\Classes\PaymentService\PaymentOperationsMonitor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RecordPaymentQueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public function handle(PaymentOperationsMonitor $monitor): void
    {
        $monitor->recordQueueHeartbeat();
    }
}
