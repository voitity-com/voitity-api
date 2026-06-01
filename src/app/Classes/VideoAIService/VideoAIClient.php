<?php

namespace App\Classes\VideoAIService;

interface VideoAIClient
{
    /**
     * Create an image from a source image and prompt.
     */
    public function createImage(string $sourceImage, string $prompt, string $ratio = ''): VideoAIImage;

    /**
     * Create a video from a source image and prompt.
     */
    public function createVideo(string $sourceImage, string $prompt, string $ratio = '', int $duration = 5): VideoAIVideo;

    /**
     * Get an image generation task by provider source ID.
     */
    public function getImage(string $sourceId): VideoAIImage;

    /**
     * Get a video generation task by provider source ID.
     */
    public function getVideo(string $sourceId): VideoAIVideo;
}
