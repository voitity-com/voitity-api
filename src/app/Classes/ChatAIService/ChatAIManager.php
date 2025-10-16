<?php

namespace App\Classes\ChatAIService;

use App\Classes\ChatAIService\OpenAI\OpenAIClient;
use Illuminate\Support\Manager;
use InvalidArgumentException;

class ChatAIManager extends Manager
{
    /**
     * Get the default Chat AI driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('chatai.default', 'openai');
    }

    /**
     * Create an OpenAI Chat AI driver instance.
     */
    public function createOpenaiDriver(): ChatAIClient
    {
        $config = $this->config->get('chatai.drivers.openai', []);

        return new OpenAIClient(
            apiKey: $config['api_key'] ?? null,
            baseUrl: $config['base_url'] ?? null,
            defaultModel: $config['default_model'] ?? null,
            whisperModel: $config['whisper_model'] ?? null,
        );
    }

    /**
     * Retrieve a Chat AI client instance.
     */
    public function driver($driver = null): ChatAIClient
    {
        return parent::driver($driver);
    }

    /**
     * Create a custom Chat AI driver instance.
     *
     * @param array{via:mixed} $config
     */
    protected function createCustomDriver(array $config): ChatAIClient
    {
        if (!isset($config['via'])) {
            throw new InvalidArgumentException('Custom chat AI driver must specify a "via" callable.');
        }

        return $this->container->call($config['via']);
    }
}
