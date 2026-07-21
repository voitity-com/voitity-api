<?php

namespace App\Jobs\Subscriptions;

use App\Classes\Subscriptions\SubscriptionBillingService;
use App\Classes\UsdCopRateService\UsdCopRateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class BillDueRecurringSubscriptions implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @return array{processed:int,approved:int,pending:int,failed:int,skipped:int}
     */
    public function handle(SubscriptionBillingService $subscriptionBillingService, UsdCopRateService $usdCopRateService): array
    {
        $usdCopRateService->syncConfig();

        return $subscriptionBillingService->billDueRecurringSubscriptions();
    }
}
