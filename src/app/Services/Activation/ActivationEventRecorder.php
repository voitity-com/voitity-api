<?php

namespace App\Services\Activation;

use App\Enums\ActivationEventType;
use App\Models\ActivationEvent;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Arr;

class ActivationEventRecorder
{
    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $attribution
     */
    public function record(
        User $user,
        ActivationEventType $type,
        string $idempotencyKey,
        ?Profile $profile = null,
        ?Subscription $subscription = null,
        array $metadata = [],
        array $attribution = [],
    ): ActivationEvent {
        $event = ActivationEvent::query()->firstOrNew(['idempotency_key' => $idempotencyKey]);
        $event->fill([
            'user_id' => $user->id,
            'profile_id' => $profile?->id,
            'subscription_id' => $subscription?->id,
            'event_type' => $type,
            'metadata' => array_replace($metadata, (array) ($event->metadata ?? [])),
            'occurred_at' => $event->occurred_at ?? now(),
            ...$this->attribution($attribution, $event),
        ]);
        $event->save();

        return $event;
    }

    /** @param array<string, mixed> $attribution */
    private function attribution(array $attribution, ActivationEvent $event): array
    {
        $allowed = Arr::only($attribution, ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content']);

        return collect($allowed)
            ->mapWithKeys(function (mixed $value, string $key) use ($event): array {
                $normalized = trim((string) $value);

                return [$key => $normalized !== '' ? mb_substr($normalized, 0, 255) : $event->getAttribute($key)];
            })
            ->all();
    }
}
