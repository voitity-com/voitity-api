<?php

namespace App\Listeners\Subscriptions;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Enums\SubscriptionUsageType;
use App\Events\AI\Videos\AiVideoForAvatarCreated;
use App\Models\AiVideo;
use App\Models\ProfileAvatar;
use App\Models\SubscriptionUse;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class TrackAvatarVideoUsage implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public function __construct(private readonly SubscriptionUsageRecorder $recorder) {}

    public function handle(AiVideoForAvatarCreated $event): void
    {
        $aiVideo = $event->aiVideo->fresh();

        if (! $aiVideo) {
            return;
        }

        $seconds = (int) config('videoai.drivers.'.($aiVideo->source ?: config('videoai.default', 'runway')).'.default_duration', 5);
        $avatar = $this->profileAvatarFor($event, $aiVideo);

        if (! $avatar && $this->hasProfileAvatarReservation($aiVideo)) {
            return;
        }

        $idempotencyKey = $avatar
            ? "avatar-video:profile-avatar:{$avatar->id}"
            : "avatar-video:{$aiVideo->id}";
        $sourceType = $avatar ? ProfileAvatar::class : AiVideo::class;
        $sourceId = $avatar ? (string) $avatar->id : (string) $aiVideo->id;

        $this->recorder->record(
            userId: $aiVideo->user_id,
            usageType: SubscriptionUsageType::AvatarVideoCreated,
            amounts: ['avatar_video_seconds' => max(1, $seconds)],
            idempotencyKey: $idempotencyKey,
            profileId: $aiVideo->profile_id,
            sourceType: $sourceType,
            sourceId: $sourceId,
            metadata: [
                'provider' => $aiVideo->source,
                'provider_source_id' => $aiVideo->source_id,
                'ai_video_id' => $aiVideo->id,
                'profile_avatar_id' => $avatar?->id,
            ]
        );
    }

    private function profileAvatarFor(AiVideoForAvatarCreated $event, AiVideo $aiVideo): ?ProfileAvatar
    {
        if ($event->aiImage) {
            $avatar = ProfileAvatar::where('aiimage_id', $event->aiImage->id)->first();

            if ($avatar) {
                return $avatar;
            }
        }

        if ($aiVideo->aiimage_id) {
            $avatar = ProfileAvatar::where('aiimage_id', $aiVideo->aiimage_id)->first();

            if ($avatar) {
                return $avatar;
            }
        }

        $avatar = ProfileAvatar::where('ai_video_id', $aiVideo->id)->first();

        if ($avatar) {
            return $avatar;
        }

        return ProfileAvatar::query()
            ->where('user_id', $aiVideo->user_id)
            ->where('profile_id', $aiVideo->profile_id)
            ->where('status', ProfileAvatar::STATUS_PROCESSING)
            ->latest('id')
            ->first();
    }

    private function hasProfileAvatarReservation(AiVideo $aiVideo): bool
    {
        return SubscriptionUse::query()
            ->where('user_id', $aiVideo->user_id)
            ->where('profile_id', $aiVideo->profile_id)
            ->where('usage_type', SubscriptionUsageType::AvatarVideoCreated)
            ->where('source_type', ProfileAvatar::class)
            ->where('idempotency_key', 'like', 'avatar-video:profile-avatar:%')
            ->exists();
    }
}
