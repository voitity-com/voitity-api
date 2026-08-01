<?php

namespace App\Services\Insights;

use App\Enums\ProfileInsightEventType;
use App\Models\Message;
use App\Models\Profile;
use App\Models\ProfileInteractionEvent;
use App\Models\ProfileProduct;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class ProfileInteractionRecorder
{
    public function record(array $attributes): ProfileInteractionEvent
    {
        $event = ProfileInteractionEvent::query()->firstOrCreate(
            ['idempotency_key' => (string) $attributes['idempotency_key']],
            $attributes,
        );

        Log::info('Profile insight interaction recorded.', [
            'event_id' => $event->id,
            'profile_id' => $event->profile_id,
            'chat_id' => $event->chat_id,
            'event_type' => $event->event_type->value,
            'provider' => $event->provider,
            'surface' => $event->surface,
            'created' => $event->wasRecentlyCreated,
        ]);

        return $event;
    }

    public function recordShownMedia(Profile $profile, Message $answer, array $media): void
    {
        foreach ($media as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $subjectId = (string) ($item['id'] ?? $index);
            $provider = $this->provider($item['provider_key'] ?? $item['provider'] ?? $item['provider_label'] ?? null);
            $mediaType = str_contains(strtoupper((string) ($item['media_type'] ?? '')), 'VIDEO') ? 'video' : 'image';

            $this->record([
                'profile_id' => $profile->id,
                'chat_id' => $answer->chat_id,
                'event_type' => ProfileInsightEventType::MediaShown,
                'subject_type' => 'media',
                'subject_id' => $subjectId,
                'provider' => $provider,
                'surface' => 'chat_answer',
                'media_type' => $mediaType,
                'occurred_at' => $answer->created_at ?? now(),
                'metadata' => Arr::only($item, ['source_type']),
                'idempotency_key' => "answer:{$answer->id}:media:{$subjectId}",
            ]);
        }
    }

    /**
     * Product impressions are persisted by the server when an answer containing
     * product cards is stored. Snapshot fields keep historical reports readable
     * after the source product is unpublished, edited, or deleted.
     *
     * @param  array<int, array<string, mixed>>  $products
     */
    public function recordShownProducts(Profile $profile, Message $answer, array $products): void
    {
        $answer->loadMissing('chat');

        foreach ($products as $index => $product) {
            if (! is_array($product)) {
                continue;
            }

            $subjectId = (string) ($product['id'] ?? $index);

            $this->record([
                'profile_id' => $profile->id,
                'chat_id' => $answer->chat_id,
                'visitor_id_hash' => $answer->chat?->visitor_id_hash,
                'event_type' => ProfileInsightEventType::ProductShown,
                'subject_type' => 'product',
                'subject_id' => $subjectId,
                'subject_public_id' => $product['public_id'] ?? null,
                'subject_name' => $product['name'] ?? null,
                'subject_status' => $product['status'] ?? 'published',
                'subject_image_url' => $product['image_url'] ?? null,
                'destination_type' => $product['destination_type'] ?? null,
                'surface' => 'chat_answer',
                'occurred_at' => $answer->created_at ?? now(),
                'metadata' => [],
                'idempotency_key' => "answer:{$answer->id}:product:{$subjectId}",
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function productSnapshot(ProfileProduct $product): array
    {
        return [
            'subject_public_id' => $product->public_id,
            'subject_name' => $product->name,
            'subject_status' => $product->status->value,
            'subject_image_url' => $product->image_url,
            'destination_type' => $product->destination_type->value,
        ];
    }

    public function provider(mixed $value): ?string
    {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';

        return $value !== '' ? $value : null;
    }
}
