<?php

namespace App\Listeners\Subscriptions;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Enums\SubscriptionUsageType;
use App\Events\AI\Videos\AiVideoForAvatarCreated;
use App\Models\AiVideo;
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

        $this->recorder->record(
            userId: $aiVideo->user_id,
            usageType: SubscriptionUsageType::AvatarVideoCreated,
            amounts: ['avatar_video_seconds' => max(1, $seconds)],
            idempotencyKey: "avatar-video:{$aiVideo->id}",
            profileId: $aiVideo->profile_id,
            sourceType: AiVideo::class,
            sourceId: (string) $aiVideo->id,
            metadata: [
                'provider' => $aiVideo->source,
                'provider_source_id' => $aiVideo->source_id,
            ]
        );
    }
}
