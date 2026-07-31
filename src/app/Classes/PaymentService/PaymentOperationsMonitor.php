<?php

namespace App\Classes\PaymentService;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

class PaymentOperationsMonitor
{
    public function recordSchedulerHeartbeat(): void
    {
        $this->record('scheduler');
    }

    public function recordQueueHeartbeat(): void
    {
        $this->record('queue');
    }

    public function recordValidWebhook(): void
    {
        $this->record('webhook');
    }

    /**
     * @return array{
     *     healthy: bool,
     *     checked_at: string,
     *     scheduler: array{healthy: bool, last_seen_at: ?string},
     *     queue: array{healthy: bool, last_seen_at: ?string},
     *     webhook: array{last_valid_at: ?string}
     * }
     */
    public function status(): array
    {
        $scheduler = $this->heartbeatStatus(
            'scheduler',
            (int) config('payment.operations.scheduler_stale_after_seconds', 180),
        );
        $queue = $this->heartbeatStatus(
            'queue',
            (int) config('payment.operations.queue_stale_after_seconds', 300),
        );

        return [
            'healthy' => $scheduler['healthy'] && $queue['healthy'],
            'checked_at' => now()->toIso8601String(),
            'scheduler' => $scheduler,
            'queue' => $queue,
            'webhook' => [
                'last_valid_at' => $this->read('webhook')?->toIso8601String(),
            ],
        ];
    }

    private function record(string $component): void
    {
        Cache::put(
            $this->cacheKey($component),
            now()->toIso8601String(),
            now()->addSeconds((int) config('payment.operations.heartbeat_retention_seconds', 86400)),
        );
    }

    /**
     * @return array{healthy: bool, last_seen_at: ?string}
     */
    private function heartbeatStatus(string $component, int $staleAfterSeconds): array
    {
        $lastSeenAt = $this->read($component);
        $ageInSeconds = $lastSeenAt
            ? max(0, now()->getTimestamp() - $lastSeenAt->getTimestamp())
            : null;

        return [
            'healthy' => $ageInSeconds !== null && $ageInSeconds <= max(1, $staleAfterSeconds),
            'last_seen_at' => $lastSeenAt?->toIso8601String(),
        ];
    }

    private function read(string $component): ?CarbonImmutable
    {
        $value = Cache::get($this->cacheKey($component));

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function cacheKey(string $component): string
    {
        return sprintf(
            '%s:%s',
            (string) config('payment.operations.cache_key_prefix', 'payments:operations'),
            $component,
        );
    }
}
