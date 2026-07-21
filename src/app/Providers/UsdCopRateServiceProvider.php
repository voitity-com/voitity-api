<?php

namespace App\Providers;

use App\Classes\UsdCopRateService\UsdCopRateClient;
use App\Classes\UsdCopRateService\UsdCopRateManager;
use App\Classes\UsdCopRateService\UsdCopRateService;
use Illuminate\Support\ServiceProvider;

class UsdCopRateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(UsdCopRateManager::class, fn ($app) => new UsdCopRateManager($app));

        $this->app->bind(UsdCopRateClient::class, function ($app) {
            return $app->make(UsdCopRateManager::class)->driver();
        });

        $this->app->bind(UsdCopRateService::class, function ($app) {
            return new UsdCopRateService($app->make(UsdCopRateManager::class));
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
        return ['usd-cop-rate'];
    }
}
