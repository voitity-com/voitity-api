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
        $this->app->singleton('voice', function ($app) {
            return new VoiceManager($app);
        });

        $this->app->bind(VoiceClient::class, function ($app) {
            return $app['voice']->driver();
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
