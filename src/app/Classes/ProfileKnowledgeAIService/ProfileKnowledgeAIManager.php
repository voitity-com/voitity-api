<?php

namespace App\Classes\ProfileKnowledgeAIService;

use App\Classes\ProfileKnowledgeAIService\Local\LocalProfileKnowledgeClient;
use App\Classes\ProfileKnowledgeAIService\OpenAI\OpenAIProfileKnowledgeClient;
use Illuminate\Support\Manager;
use InvalidArgumentException;

class ProfileKnowledgeAIManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('profile-knowledge-ai.default', 'openai');
    }

    public function createOpenaiDriver(): ProfileKnowledgeAIClient
    {
        $config = $this->config->get('profile-knowledge-ai.drivers.openai', []);

        return new OpenAIProfileKnowledgeClient(
            apiKey: $config['api_key'] ?? null,
            baseUrl: $config['base_url'] ?? null,
            defaultModel: $config['default_model'] ?? null,
            maxTokens: isset($config['max_tokens']) ? (int) $config['max_tokens'] : null,
            temperature: isset($config['temperature']) ? (float) $config['temperature'] : null,
        );
    }

    public function createLocalDriver(): ProfileKnowledgeAIClient
    {
        return new LocalProfileKnowledgeClient;
    }

    public function driver($driver = null): ProfileKnowledgeAIClient
    {
        return parent::driver($driver);
    }

    /**
     * @param  array{via:mixed}  $config
     */
    protected function createCustomDriver(array $config): ProfileKnowledgeAIClient
    {
        if (! isset($config['via'])) {
            throw new InvalidArgumentException('Custom profile knowledge AI driver must specify a "via" callable.');
        }

        return $this->container->call($config['via']);
    }
}
