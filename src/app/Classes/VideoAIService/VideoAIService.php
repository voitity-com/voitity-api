<?php

namespace App\Classes\VideoAIService;

class VideoAIService
{
    protected VideoAIClient $videoAIClient;

    public function __construct(?VideoAIClient $videoAIClient = null)
    {
        $this->videoAIClient = $videoAIClient ?: app(VideoAIManager::class)->driver();
    }

    public function createImage(string $sourceImage, string $prompt, string $ratio = ''): VideoAIImage
    {
        return $this->videoAIClient->createImage($sourceImage, $prompt, $ratio);
    }

    public function createVideo(string $sourceImage, string $prompt, string $ratio = '', int $duration = 5): VideoAIVideo
    {
        return $this->videoAIClient->createVideo($sourceImage, $prompt, $ratio, $duration);
    }

    public function getImage(string $sourceId): VideoAIImage
    {
        return $this->videoAIClient->getImage($sourceId);
    }

    public function getVideo(string $sourceId): VideoAIVideo
    {
        return $this->videoAIClient->getVideo($sourceId);
    }

    public function getVideoAIClient(): VideoAIClient
    {
        return $this->videoAIClient;
    }

    public function setVideoAIClient(VideoAIClient $videoAIClient): self
    {
        $this->videoAIClient = $videoAIClient;
        return $this;
    }
}
