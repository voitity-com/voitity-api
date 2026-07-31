<?php

namespace App\Classes\Subscriptions;

use App\Enums\SubscriptionUsageType;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\SubscriptionUse;
use App\Models\User;

class AvatarGenerationUsageService
{
    public function __construct(
        private readonly SubscriptionUsageRecorder $recorder,
    ) {}

    public function reserve(User $owner, Profile $profile, ProfileAvatar $avatar): SubscriptionUse
    {
        return $this->recorder->reserve(
            userId: (int) $owner->id,
            usageType: SubscriptionUsageType::AvatarGenerated,
            amounts: [
                'avatar_images' => 1,
                'avatar_video_seconds' => max(1, (int) $avatar->video_duration_seconds),
            ],
            idempotencyKey: $this->key($avatar),
            profileId: (int) $profile->id,
            sourceType: ProfileAvatar::class,
            sourceId: (string) $avatar->id,
            metadata: [
                'duration_seconds' => (int) $avatar->video_duration_seconds,
                'reservation' => 'avatar_generation',
            ],
        );
    }

    /** @param array<string, mixed> $metadata */
    public function finalize(ProfileAvatar $avatar, array $metadata = []): ?SubscriptionUse
    {
        if (! $this->exists($avatar)) {
            return null;
        }

        return $this->recorder->finalize($this->key($avatar), $metadata);
    }

    public function release(ProfileAvatar $avatar): bool
    {
        $released = $this->recorder->release($this->key($avatar));

        // These keys are retained for operations created before atomic avatar usage.
        $this->recorder->release("avatar-image:profile-avatar:{$avatar->id}");
        $this->recorder->release("avatar-video:profile-avatar:{$avatar->id}");

        return $released;
    }

    public function exists(ProfileAvatar $avatar): bool
    {
        return SubscriptionUse::where('idempotency_key', $this->key($avatar))->exists();
    }

    public function key(ProfileAvatar $avatar): string
    {
        return "avatar-generation:profile-avatar:{$avatar->id}";
    }
}
