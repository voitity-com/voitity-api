<?php

namespace App\Classes\YouTubeService;

interface YouTubeClient
{
    public function getChannel(string $reference): YouTubeChannel;

    public function getChannelById(string $channelId): YouTubeChannel;

    public function getVideo(string $reference): YouTubeVideo;

    /**
     * @param  array<int, string>  $videoIds
     * @return array<string, YouTubeVideo>
     */
    public function getVideosById(array $videoIds): array;
}
