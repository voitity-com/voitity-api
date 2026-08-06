<?php

namespace Tests\Support;

use App\Models\Profile;
use App\Services\Features\FeatureService;

trait EnablesFeaturesForTestProfiles
{
    protected function enableFeaturesForTestProfiles(): void
    {
        Profile::created(static function (Profile $profile): void {
            app(FeatureService::class)->initializeProfileFeatures($profile, true);
        });
    }
}
