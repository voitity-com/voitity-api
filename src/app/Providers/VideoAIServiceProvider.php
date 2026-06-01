<?php

namespace App\Providers;

use App\Classes\VideoAIService\VideoAIClient;
use App\Classes\VideoAIService\VideoAIManager;
use App\Classes\VideoAIService\VideoAIService;
use Illuminate\Support\ServiceProvider;

class VideoAIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(VideoAIManager::class, fn ($app) => new VideoAIManager($app));

        $this->app->bind(VideoAIClient::class, function ($app) {
            return $app->make(VideoAIManager::class)->driver();
        });

        $this->app->bind(VideoAIService::class, function ($app) {
            return new VideoAIService($app->make(VideoAIClient::class));
        });
    }

    public function boot(): void
    {
        //
    }

    /**
     * @return array<int, string>
     */
    public function provides(): array
    {
        return ['videoai'];
    }
}
