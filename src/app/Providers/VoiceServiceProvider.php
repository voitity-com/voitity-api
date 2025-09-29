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
        
        // VoiceService should be created manually with Voice and VoiceClient when needed
        // No need for complex factory binding since VoiceManager handles driver creation
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
