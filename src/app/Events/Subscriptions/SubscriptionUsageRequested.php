<?php

namespace App\Events\Subscriptions;

use App\Enums\SubscriptionUsageType;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class SubscriptionUsageRequested
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, int>  $amounts
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public int $userId,
        public SubscriptionUsageType $usageType,
        public array $amounts,
        public ?int $profileId = null,
        public ?string $sourceType = null,
        public ?string $sourceId = null,
        public ?string $idempotencyKey = null,
        public array $metadata = []
    ) {
        $this->idempotencyKey ??= Str::uuid()->toString();
    }
}
