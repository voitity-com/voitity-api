<?php

namespace App\Providers;

use App\Classes\VoiceService\VoiceManager;
use App\Classes\VoiceService\VoiceClient;
use App\Classes\VoiceService\VoiceService;
use App\Models\Voice;
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
            return $app->make(VoiceManager::class)->driver(); 
        });
        
        // Register VoiceService as a factory since each instance needs a specific Voice
        $this->app->bind(VoiceService::class, function ($app) {
            // This will be resolved when a Voice instance is provided
            return function (Voice $voice, ?VoiceClient $voiceClient = null) use ($app) {
                return new VoiceService(
                    $voice,
                    $voiceClient ?: $app->make(VoiceClient::class)
                );
            };
        });

        // Register a VoiceService factory interface for cleaner DI
        $this->app->bind('voice.service.factory', function ($app) {
            return $app->make(VoiceService::class);
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
