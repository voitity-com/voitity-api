<?php

namespace App\Listeners\Subscriptions;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Enums\SubscriptionUsageType;
use App\Events\AI\Images\AiImageForAvatarCreated;
use App\Models\AiImage;
use App\Models\ProfileAvatar;
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

        $avatar = ProfileAvatar::where('aiimage_id', $aiImage->id)->first();
        $idempotencyKey = $avatar
            ? "avatar-image:profile-avatar:{$avatar->id}"
            : "avatar-image:{$aiImage->id}";
        $sourceType = $avatar ? ProfileAvatar::class : AiImage::class;
        $sourceId = $avatar ? (string) $avatar->id : (string) $aiImage->id;

        $this->recorder->record(
            userId: $aiImage->user_id,
            usageType: SubscriptionUsageType::AvatarImageCreated,
            amounts: ['avatar_images' => 1],
            idempotencyKey: $idempotencyKey,
            profileId: $aiImage->profile_id,
            sourceType: $sourceType,
            sourceId: $sourceId,
            metadata: [
                'provider' => $aiImage->source,
                'provider_source_id' => $aiImage->source_id,
                'ai_image_id' => $aiImage->id,
                'profile_avatar_id' => $avatar?->id,
            ]
        );
    }
}
