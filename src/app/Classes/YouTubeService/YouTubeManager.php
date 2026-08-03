<?php

namespace App\Classes\YouTubeService;

use App\Classes\YouTubeService\Google\GoogleYouTubeClient;
use Illuminate\Support\Manager;

class YouTubeManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return (string) config('youtube.default', 'google');
    }

    protected function createGoogleDriver(): YouTubeClient
    {
        return $this->container->make(GoogleYouTubeClient::class);
    }

    protected function createCustomDriver(): YouTubeClient
    {
        $via = config('youtube.via');

        if (! is_string($via) || $via === '') {
            throw new \InvalidArgumentException('Custom YouTube driver requires a via class.');
        }

        return $this->container->make($via);
    }
}
