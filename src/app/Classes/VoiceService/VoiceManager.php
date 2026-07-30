<?php

namespace App\Classes\VoiceService;

use App\Classes\VoiceService\ElevenLabs\ElevenLabsVoiceClient;
use Illuminate\Support\Manager;
use InvalidArgumentException;

class VoiceManager extends Manager
{
    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('voice.default', 'elevenlabs');
    }

    /**
     * Create an ElevenLabs voice driver instance.
     */
    public function createElevenlabsDriver(): VoiceClient
    {
        return new ElevenLabsVoiceClient;
    }

    /**
     * Get a voice client instance.
     *
     * @param  string|null  $driver
     */
    public function driver($driver = null): VoiceClient
    {
        return parent::driver($driver);
    }

    /**
     * Create a custom voice driver instance.
     */
    protected function createCustomDriver(array $config): VoiceClient
    {
        if (! isset($config['via'])) {
            throw new InvalidArgumentException('Custom voice driver must specify a "via" callable.');
        }

        return $this->container->call($config['via']);
    }
}
