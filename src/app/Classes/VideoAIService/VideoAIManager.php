<?php

namespace App\Classes\VideoAIService;

use App\Classes\VideoAIService\Runway\RunwayVideoAI;
use Illuminate\Support\Manager;
use InvalidArgumentException;

class VideoAIManager extends Manager
{
    /**
     * Get the default Video AI driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('videoai.default', 'runway');
    }

    /**
     * Create a Runway Video AI driver instance.
     */
    public function createRunwayDriver(): VideoAIClient
    {
        $config = $this->config->get('videoai.drivers.runway', []);

        return new RunwayVideoAI(
            apiKey: $config['api_key'] ?? null,
            baseUrl: $config['base_url'] ?? null,
            apiVersion: $config['api_version'] ?? null,
            imageModel: $config['image_model'] ?? null,
            videoModel: $config['video_model'] ?? null,
            referenceImageTag: $config['reference_image_tag'] ?? null,
            defaultImageRatio: $config['default_image_ratio'] ?? null,
            defaultVideoRatio: $config['default_video_ratio'] ?? null,
            defaultDuration: $config['default_duration'] ?? null,
        );
    }

    /**
     * Retrieve a Video AI client instance.
     */
    public function driver($driver = null): VideoAIClient
    {
        return parent::driver($driver);
    }

    /**
     * Create a custom Video AI driver instance.
     *
     * @param array{via:mixed} $config
     */
    protected function createCustomDriver(array $config): VideoAIClient
    {
        if (!isset($config['via'])) {
            throw new InvalidArgumentException('Custom video AI driver must specify a "via" callable.');
        }

        return $this->container->call($config['via']);
    }
}
