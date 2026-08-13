<?php

namespace App\Classes\PublicProfiles;

use App\Enums\ProfileDomainStatus;
use App\Enums\ProfileStatus;
use App\Models\Profile;

class PublicProfileAccess
{
    public function findByAlias(string $alias): ?Profile
    {
        return Profile::query()
            ->where('alias', $alias)
            ->where('active', true)
            ->where('status', ProfileStatus::Published->value)
            ->first();
    }

    public function findByDomain(string $hostname): ?Profile
    {
        $normalized = strtolower(rtrim(trim($hostname), '.'));

        return Profile::query()
            ->where('active', true)
            ->where('status', ProfileStatus::Published->value)
            ->whereHas('domain', fn ($query) => $query
                ->where('hostname', $normalized)
                ->where('status', ProfileDomainStatus::Active->value))
            ->first();
    }

    public function isVisible(Profile $profile): bool
    {
        return (bool) $profile->active
            && $profile->status === ProfileStatus::Published;
    }
}
