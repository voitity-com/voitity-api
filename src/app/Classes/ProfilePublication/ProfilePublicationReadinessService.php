<?php

namespace App\Classes\ProfilePublication;

use App\Enums\ProfileSourceStatus;
use App\Enums\ProfileStatus;
use App\Models\Profile;
use App\Models\ProfileAvatar;

class ProfilePublicationReadinessService
{
    public function evaluate(Profile $profile): array
    {
        $requirements = [
            $this->requirement('name', filled($profile->name)),
            $this->requirement('alias', filled($profile->alias)),
            $this->requirement('description', filled($profile->description)),
            $this->requirement('avatar', $this->hasActiveAvatar($profile)),
            $this->requirement('source', $this->hasApprovedSyncedSource($profile)),
        ];

        $missing = collect($requirements)
            ->filter(fn (array $requirement): bool => ! $requirement['passed'])
            ->pluck('key')
            ->values()
            ->all();

        return [
            'can_activate' => $missing === [],
            'is_published' => (bool) $profile->active && $this->profileStatus($profile) === ProfileStatus::Published,
            'requirements' => $requirements,
            'missing' => $missing,
        ];
    }

    private function requirement(string $key, bool $passed): array
    {
        return [
            'key' => $key,
            'passed' => $passed,
        ];
    }

    private function hasActiveAvatar(Profile $profile): bool
    {
        if ($profile->relationLoaded('avatars')) {
            return $profile->avatars->contains(
                fn (ProfileAvatar $avatar): bool => $avatar->status === ProfileAvatar::STATUS_ACTIVE && filled($avatar->file)
            );
        }

        return $profile->avatars()
            ->where('status', ProfileAvatar::STATUS_ACTIVE)
            ->whereNotNull('file')
            ->where('file', '<>', '')
            ->exists();
    }

    private function hasApprovedSyncedSource(Profile $profile): bool
    {
        if ($profile->relationLoaded('sources')) {
            return $profile->sources->contains(
                fn ($source): bool => $this->sourceStatus($source) === ProfileSourceStatus::Indexed
                    && filled($source->approved_at)
                    && filled($source->indexed_at)
            );
        }

        return $profile->sources()
            ->where('status', ProfileSourceStatus::Indexed->value)
            ->whereNotNull('approved_at')
            ->whereNotNull('indexed_at')
            ->exists();
    }

    private function profileStatus(Profile $profile): ?ProfileStatus
    {
        if ($profile->status instanceof ProfileStatus) {
            return $profile->status;
        }

        return ProfileStatus::tryFrom((string) $profile->status);
    }

    private function sourceStatus($source): ?ProfileSourceStatus
    {
        if ($source->status instanceof ProfileSourceStatus) {
            return $source->status;
        }

        return ProfileSourceStatus::tryFrom((string) $source->status);
    }
}
