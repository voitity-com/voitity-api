<?php

namespace App\Classes\Subscriptions;

class AvatarGenerationSpecification
{
    public function videoDurationSeconds(): int
    {
        $driver = (string) config('videoai.default', 'runway');

        return max(1, (int) config("videoai.drivers.{$driver}.default_duration", 2));
    }

    /**
     * @return array{avatar_images: int, avatar_video_seconds: int}
     */
    public function usageAmounts(): array
    {
        return [
            'avatar_images' => 1,
            'avatar_video_seconds' => $this->videoDurationSeconds(),
        ];
    }
}
