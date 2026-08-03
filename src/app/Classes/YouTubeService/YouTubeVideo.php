<?php

namespace App\Classes\YouTubeService;

use DateTimeImmutable;

final readonly class YouTubeVideo
{
    /**
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $channelId,
        public string $channelTitle,
        public string $url,
        public ?string $thumbnailUrl,
        public bool $embeddable,
        public string $privacyStatus,
        public ?DateTimeImmutable $publishedAt,
        public array $response = [],
    ) {}

    public function isAccessible(): bool
    {
        return $this->embeddable && in_array($this->privacyStatus, ['public', 'unlisted'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'channel_id' => $this->channelId,
            'channel_title' => $this->channelTitle,
            'url' => $this->url,
            'thumbnail_url' => $this->thumbnailUrl,
            'embeddable' => $this->embeddable,
            'privacy_status' => $this->privacyStatus,
            'published_at' => $this->publishedAt?->format(DATE_ATOM),
        ];
    }
}
