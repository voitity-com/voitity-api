<?php

namespace App\Listeners\Subscriptions;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Enums\SubscriptionUsageType;
use App\Events\AI\Images\AiImageForAvatarCreated;
use App\Models\AiImage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class TrackAvatarImageUsage implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public function __construct(private readonly SubscriptionUsageRecorder $recorder) {}

    public function handle(AiImageForAvatarCreated $event): void
    {
        $aiImage = $event->aiImage->fresh();

        if (! $aiImage) {
            return;
        }

        $this->recorder->record(
            userId: $aiImage->user_id,
            usageType: SubscriptionUsageType::AvatarImageCreated,
            amounts: ['avatar_images' => 1],
            idempotencyKey: "avatar-image:{$aiImage->id}",
            profileId: $aiImage->profile_id,
            sourceType: AiImage::class,
            sourceId: (string) $aiImage->id,
            metadata: [
                'provider' => $aiImage->source,
                'provider_source_id' => $aiImage->source_id,
            ]
        );
    }
}
