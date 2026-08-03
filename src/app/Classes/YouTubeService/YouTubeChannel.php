<?php

namespace App\Classes\YouTubeService;

final readonly class YouTubeChannel
{
    /**
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        public string $id,
        public string $title,
        public ?string $handle,
        public string $url,
        public ?string $thumbnailUrl,
        public array $response = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'handle' => $this->handle,
            'url' => $this->url,
            'thumbnail_url' => $this->thumbnailUrl,
        ];
    }
}
