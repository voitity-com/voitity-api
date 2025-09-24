<?php

namespace App\Providers;

use App\Classes\VoiceService\VoiceManager;
use App\Classes\VoiceService\VoiceClient;
use Illuminate\Support\ServiceProvider;

class VoiceServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        $this->app->singleton(VoiceManager::class, fn($app) => new VoiceManager($app));
        
        $this->app->bind(VoiceClient::class, function ($app) {
            return $app->make(VoiceManager::class)->driver(); // según config('voice.default')
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot(): void
    {
        //
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides(): array
    {
        return ['voice'];
    }
}
