<?php

namespace App\Listeners\Subscriptions;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Enums\SubscriptionUsageType;
use App\Events\AI\Images\AiImageForAvatarCreated;
use App\Models\AiImage;
use App\Models\ProfileAvatar;
use App\Models\SubscriptionUse;
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

        $avatar = ProfileAvatar::where('aiimage_id', $aiImage->id)->first()
            ?: $this->processingProfileAvatarFor($aiImage);

        if (! $avatar && $this->hasProfileAvatarReservation($aiImage)) {
            return;
        }

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

    private function hasProfileAvatarReservation(AiImage $aiImage): bool
    {
        return SubscriptionUse::query()
            ->where('user_id', $aiImage->user_id)
            ->where('profile_id', $aiImage->profile_id)
            ->where('usage_type', SubscriptionUsageType::AvatarImageCreated)
            ->where('source_type', ProfileAvatar::class)
            ->where('idempotency_key', 'like', 'avatar-image:profile-avatar:%')
            ->exists();
    }

    private function processingProfileAvatarFor(AiImage $aiImage): ?ProfileAvatar
    {
        return ProfileAvatar::query()
            ->where('user_id', $aiImage->user_id)
            ->where('profile_id', $aiImage->profile_id)
            ->where('status', ProfileAvatar::STATUS_PROCESSING)
            ->latest('id')
            ->first();
    }
}
