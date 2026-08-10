<?php

namespace App\Classes\Subscriptions;

use App\Enums\SubscriptionUsageType;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\SubscriptionUse;
use App\Models\User;
use Illuminate\Support\Facades\Log;

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

    /** @param array<string, mixed> $metadata */
    public function finalizeImageOnly(ProfileAvatar $avatar, array $metadata = []): ?SubscriptionUse
    {
        $key = $this->key($avatar);
        $use = SubscriptionUse::where('idempotency_key', $key)->first();

        if (! $use || $use->status === SubscriptionUse::STATUS_RELEASED) {
            return null;
        }

        if ($use->status === SubscriptionUse::STATUS_FINALIZED) {
            return $use;
        }

        if ($use->status === SubscriptionUse::STATUS_RESERVED) {
            $this->recorder->replaceReservation(
                userId: (int) $avatar->user_id,
                usageType: SubscriptionUsageType::AvatarGenerated,
                amounts: [
                    'avatar_images' => 1,
                    'avatar_video_seconds' => 0,
                ],
                idempotencyKey: $key,
                profileId: (int) $avatar->profile_id,
                sourceType: ProfileAvatar::class,
                sourceId: (string) $avatar->id,
                metadata: [
                    'duration_seconds' => 0,
                    'reservation' => 'avatar_generation',
                    'partial_result' => 'enhanced_image',
                    ...$metadata,
                ],
            );
        }

        $finalized = $this->recorder->finalize($key, [
            'partial_result' => 'enhanced_image',
            ...$metadata,
        ]);

        Log::info('Avatar usage finalized for enhanced image only.', [
            'profile_avatar_id' => $avatar->id,
            'profile_id' => $avatar->profile_id,
            'subscription_use_id' => $finalized->id,
        ]);

        return $finalized;
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
