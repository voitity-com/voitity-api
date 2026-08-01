<?php

namespace App\Classes\ConversationInsights;

use App\Classes\ConversationInsights\OpenAI\OpenAIConversationInsightsClient;
use Illuminate\Support\Manager;

class ConversationInsightsManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) config('insights.classification.driver', 'openai');
    }

    protected function createOpenaiDriver(): ConversationInsightsClient
    {
        return $this->container->make(OpenAIConversationInsightsClient::class);
    }

    protected function createCustomDriver(): ConversationInsightsClient
    {
        $via = config('insights.classification.via');

        if (! is_string($via) || $via === '') {
            throw new \InvalidArgumentException('Custom conversation insights driver requires a via class.');
        }

        return $this->container->make($via);
    }
}
