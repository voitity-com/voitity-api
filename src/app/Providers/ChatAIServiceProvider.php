<?php

namespace App\Providers;

use App\Classes\ChatAIService\ChatAIClient;
use App\Classes\ChatAIService\ChatAIManager;
use Illuminate\Support\ServiceProvider;

class ChatAIServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ChatAIManager::class, fn ($app) => new ChatAIManager($app));

        $this->app->bind(ChatAIClient::class, function ($app) {
            return $app->make(ChatAIManager::class)->driver();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array<int, string>
     */
    public function provides(): array
    {
        return ['chatai'];
    }
}
