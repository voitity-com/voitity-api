<?php

namespace App\Services;

use App\Models\Profile;
use App\Models\ProfileWidget;

class ProfileWidgetService
{
    public function ensureForProfile(Profile $profile): ProfileWidget
    {
        return ProfileWidget::query()->firstOrCreate(
            ['profile_id' => $profile->id],
            ['enabled' => false],
        );
    }

    public function createForProfile(Profile $profile): ProfileWidget
    {
        return ProfileWidget::query()->create([
            'profile_id' => $profile->id,
            'enabled' => false,
        ]);
    }
}
