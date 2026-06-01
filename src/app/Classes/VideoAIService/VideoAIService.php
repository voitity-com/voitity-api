<?php

namespace App\Classes\VideoAIService;

use App\Events\AI\Images\AiImageCreated;
use App\Models\AiImage as AiImageModel;
use App\Models\Profile;
use App\Models\User;
use RuntimeException;

class VideoAIService
{
    protected VideoAIClient $videoAIClient;

    public function __construct(?VideoAIClient $videoAIClient = null)
    {
        $this->videoAIClient = $videoAIClient ?: app(VideoAIManager::class)->driver();
    }

    public function createImage(string $sourceImage, string $prompt, string $ratio = ''): AiImage
    {
        return $this->videoAIClient->createImage($sourceImage, $prompt, $ratio);
    }

    public function generateImage(
        User $user,
        string $sourceImage,
        ?Profile $profile = null,
        ?string $prompt = null,
        string $ratio = ''
    ): AiImageModel {
        $result = $this->createImage(
            $sourceImage,
            $prompt ?: config('videoai.prompts.image'),
            $ratio
        );

        if (!$result->id) {
            throw new RuntimeException('Video AI image generation did not return a source id.');
        }

        $aiImage = AiImageModel::create([
            'user_id' => $user->id,
            'profile_id' => $profile?->id,
            'source_id' => $result->id,
            'source' => config('videoai.default', 'runway'),
            'status' => $this->normalizeStatus($result->status),
            'file' => null,
        ]);

        event(new AiImageCreated($aiImage));

        return $aiImage;
    }

    public function createVideo(string $sourceImage, string $prompt, string $ratio = '', int $duration = 5): AiVideo
    {
        return $this->videoAIClient->createVideo($sourceImage, $prompt, $ratio, $duration);
    }

    public function getImage(string $sourceId): AiImage
    {
        return $this->videoAIClient->getImage($sourceId);
    }

    public function getVideo(string $sourceId): AiVideo
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

    public function normalizeStatus(string $status): string
    {
        return strtolower($status);
    }
}
