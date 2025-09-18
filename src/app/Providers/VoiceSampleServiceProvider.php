<?php

namespace App\Providers;

use App\Classes\VoiceSampleFileManager;
use Illuminate\Support\ServiceProvider;

class VoiceSampleServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->bind(VoiceSampleFileManager::class, function ($app) {
            return new VoiceSampleFileManager();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
