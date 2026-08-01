<?php

namespace App\Providers;

use App\Classes\ConversationInsights\ConversationInsightsClient;
use App\Classes\ConversationInsights\ConversationInsightsManager;
use Illuminate\Support\ServiceProvider;

class ConversationInsightsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConversationInsightsManager::class, fn ($app) => new ConversationInsightsManager($app));
        $this->app->bind(
            ConversationInsightsClient::class,
            fn ($app) => $app->make(ConversationInsightsManager::class)->driver(),
        );
    }
}
