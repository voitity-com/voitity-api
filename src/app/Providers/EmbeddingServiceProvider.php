<?php

namespace App\Providers;

use App\Classes\EmbeddingService\EmbeddingClient;
use App\Classes\EmbeddingService\EmbeddingManager;
use Illuminate\Support\ServiceProvider;

class EmbeddingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EmbeddingManager::class, fn ($app) => new EmbeddingManager($app));
        $this->app->bind(EmbeddingClient::class, fn ($app) => $app->make(EmbeddingManager::class)->driver());
    }
}
