<?php

namespace App\Providers;

use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeAIClient;
use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeAIManager;
use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeAIService;
use Illuminate\Support\ServiceProvider;

class ProfileKnowledgeAIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ProfileKnowledgeAIManager::class, fn ($app) => new ProfileKnowledgeAIManager($app));

        $this->app->bind(ProfileKnowledgeAIClient::class, function ($app) {
            return $app->make(ProfileKnowledgeAIManager::class)->driver();
        });

        $this->app->bind(ProfileKnowledgeAIService::class, function ($app) {
            return new ProfileKnowledgeAIService($app->make(ProfileKnowledgeAIManager::class));
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
        return ['profile-knowledge-ai'];
    }
}
