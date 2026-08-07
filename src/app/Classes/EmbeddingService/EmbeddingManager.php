<?php

namespace App\Classes\EmbeddingService;

use App\Classes\EmbeddingService\OpenAI\OpenAIEmbeddingClient;
use Illuminate\Support\Manager;
use InvalidArgumentException;

class EmbeddingManager extends Manager
{
    public function driver($driver = null): EmbeddingClient
    {
        /** @var EmbeddingClient $client */
        $client = parent::driver($driver);

        return $client;
    }

    public function getDefaultDriver(): string
    {
        return (string) config('ai-knowledge.embedding.default', 'openai');
    }

    protected function createOpenaiDriver(): EmbeddingClient
    {
        $config = (array) config('ai-knowledge.embedding.drivers.openai', []);

        return new OpenAIEmbeddingClient(
            apiKey: $config['api_key'] ?? null,
            baseUrl: $config['base_url'] ?? null,
            model: $config['model'] ?? null,
            dimensions: isset($config['dimensions']) ? (int) $config['dimensions'] : null,
            retryAttempts: isset($config['retry_attempts']) ? (int) $config['retry_attempts'] : null,
            retryDelayMs: isset($config['retry_delay_ms']) ? (int) $config['retry_delay_ms'] : null,
        );
    }

    protected function createCustomDriver(array $config): EmbeddingClient
    {
        if (! isset($config['via'])) {
            throw new InvalidArgumentException('Custom embedding drivers require a via resolver.');
        }

        $client = $this->container->call($config['via'], ['app' => $this->container]);

        if (! $client instanceof EmbeddingClient) {
            throw new InvalidArgumentException('Custom embedding driver must implement EmbeddingClient.');
        }

        return $client;
    }
}
