<?php

namespace App\Listeners\Subscriptions;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Events\Subscriptions\SubscriptionUsageRequested;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class RecordSubscriptionUsage implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public function __construct(private readonly SubscriptionUsageRecorder $recorder) {}

    public function handle(SubscriptionUsageRequested $event): void
    {
        $this->recorder->record(
            userId: $event->userId,
            usageType: $event->usageType,
            amounts: $event->amounts,
            idempotencyKey: $event->idempotencyKey,
            profileId: $event->profileId,
            sourceType: $event->sourceType,
            sourceId: $event->sourceId,
            metadata: $event->metadata
        );
    }
}
