<?php

namespace App\Providers;

use App\Classes\YouTubeService\YouTubeClient;
use App\Classes\YouTubeService\YouTubeManager;
use Illuminate\Support\ServiceProvider;

class YouTubeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(YouTubeManager::class, fn ($app) => new YouTubeManager($app));
        $this->app->bind(
            YouTubeClient::class,
            fn ($app) => $app->make(YouTubeManager::class)->driver(),
        );
    }
}
