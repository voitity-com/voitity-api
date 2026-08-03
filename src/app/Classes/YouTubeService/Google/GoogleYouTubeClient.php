<?php

namespace App\Classes\YouTubeService\Google;

use App\Classes\YouTubeService\YouTubeChannel;
use App\Classes\YouTubeService\YouTubeClient;
use App\Classes\YouTubeService\YouTubeVideo;
use DateTimeImmutable;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

class GoogleYouTubeClient implements YouTubeClient
{
    public function getChannel(string $reference): YouTubeChannel
    {
        [$filter, $value] = $this->channelFilter($reference);

        return $this->fetchChannel($filter, $value);
    }

    public function getChannelById(string $channelId): YouTubeChannel
    {
        if (! preg_match('/^UC[A-Za-z0-9_-]{20,}$/', $channelId)) {
            throw new InvalidArgumentException('The YouTube channel ID is invalid.');
        }

        return $this->fetchChannel('id', $channelId);
    }

    public function getVideo(string $reference): YouTubeVideo
    {
        $videoId = $this->videoId($reference);
        $videos = $this->getVideosById([$videoId]);

        if (! isset($videos[$videoId])) {
            throw new InvalidArgumentException('The YouTube video was not found or is not public.');
        }

        return $videos[$videoId];
    }

    public function getVideosById(array $videoIds): array
    {
        $videoIds = collect($videoIds)
            ->map(fn ($id): string => trim((string) $id))
            ->filter(fn (string $id): bool => preg_match('/^[A-Za-z0-9_-]{11}$/', $id) === 1)
            ->unique()
            ->values();

        if ($videoIds->isEmpty()) {
            return [];
        }

        $videos = [];

        foreach ($videoIds->chunk(50) as $chunk) {
            $response = $this->request()->get('/videos', [
                'id' => $chunk->implode(','),
                'part' => 'snippet,status',
                'fields' => 'items(id,snippet(channelId,channelTitle,publishedAt,title,thumbnails),status(embeddable,privacyStatus))',
            ]);

            if (! $response->successful()) {
                throw new RuntimeException($this->errorMessage('video', $response->status(), $response->json()));
            }

            foreach ((array) $response->json('items', []) as $item) {
                if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                    continue;
                }

                $video = $this->videoFromResponse($item);
                $videos[$video->id] = $video;
            }
        }

        return $videos;
    }

    private function fetchChannel(string $filter, string $value): YouTubeChannel
    {
        $response = $this->request()->get('/channels', [
            $filter => $value,
            'part' => 'snippet',
            'fields' => 'items(id,snippet(customUrl,title,thumbnails))',
        ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage('channel', $response->status(), $response->json()));
        }

        $item = $response->json('items.0');

        if (! is_array($item) || ! is_string($item['id'] ?? null)) {
            throw new InvalidArgumentException('The YouTube channel was not found.');
        }

        $id = (string) $item['id'];
        $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
        $handle = $this->nullableString($snippet['customUrl'] ?? null);

        return new YouTubeChannel(
            id: $id,
            title: $this->nullableString($snippet['title'] ?? null) ?: $id,
            handle: $handle,
            url: $handle ? 'https://www.youtube.com/'.ltrim($handle, '/') : 'https://www.youtube.com/channel/'.$id,
            thumbnailUrl: $this->bestThumbnail($snippet['thumbnails'] ?? null),
            response: $item,
        );
    }

    private function videoFromResponse(array $item): YouTubeVideo
    {
        $id = (string) $item['id'];
        $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
        $status = is_array($item['status'] ?? null) ? $item['status'] : [];
        $publishedAt = $this->nullableString($snippet['publishedAt'] ?? null);

        return new YouTubeVideo(
            id: $id,
            title: $this->nullableString($snippet['title'] ?? null) ?: $id,
            channelId: $this->nullableString($snippet['channelId'] ?? null) ?: '',
            channelTitle: $this->nullableString($snippet['channelTitle'] ?? null) ?: '',
            url: 'https://www.youtube.com/watch?v='.$id,
            thumbnailUrl: $this->bestThumbnail($snippet['thumbnails'] ?? null),
            embeddable: (bool) ($status['embeddable'] ?? false),
            privacyStatus: strtolower($this->nullableString($status['privacyStatus'] ?? null) ?: 'unknown'),
            publishedAt: $publishedAt ? new DateTimeImmutable($publishedAt) : null,
            response: $item,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function channelFilter(string $reference): array
    {
        $reference = trim($reference);

        if (preg_match('/^UC[A-Za-z0-9_-]{20,}$/', $reference)) {
            return ['id', $reference];
        }

        if (str_starts_with($reference, '@')) {
            return ['forHandle', $reference];
        }

        $url = $this->providerUrl($reference, ['youtube.com']);
        $segments = array_values(array_filter(explode('/', trim($url->getPath(), '/'))));

        if (($segments[0] ?? null) === 'channel' && isset($segments[1])) {
            return ['id', $segments[1]];
        }

        if (isset($segments[0]) && str_starts_with($segments[0], '@')) {
            return ['forHandle', $segments[0]];
        }

        if (($segments[0] ?? null) === 'user' && isset($segments[1])) {
            return ['forUsername', $segments[1]];
        }

        throw new InvalidArgumentException('Use a YouTube channel URL based on @handle or channel ID.');
    }

    private function videoId(string $reference): string
    {
        $reference = trim($reference);

        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $reference)) {
            return $reference;
        }

        $url = $this->providerUrl($reference, ['youtube.com', 'youtu.be', 'youtube-nocookie.com']);
        $host = strtolower($url->getHost());
        $segments = array_values(array_filter(explode('/', trim($url->getPath(), '/'))));
        $candidate = null;

        if ($host === 'youtu.be' || str_ends_with($host, '.youtu.be')) {
            $candidate = $segments[0] ?? null;
        } elseif (($segments[0] ?? null) === 'watch') {
            parse_str($url->getQuery(), $query);
            $candidate = $query['v'] ?? null;
        } elseif (in_array($segments[0] ?? null, ['embed', 'live', 'shorts'], true)) {
            $candidate = $segments[1] ?? null;
        } else {
            parse_str($url->getQuery(), $query);
            $candidate = $query['v'] ?? null;
        }

        if (! is_string($candidate) || preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate) !== 1) {
            throw new InvalidArgumentException('The YouTube video URL is invalid.');
        }

        return $candidate;
    }

    /**
     * @param  array<int, string>  $allowedDomains
     */
    private function providerUrl(string $value, array $allowedDomains): Uri
    {
        try {
            $url = new Uri($value);
        } catch (\Throwable) {
            throw new InvalidArgumentException('The YouTube URL is invalid.');
        }

        $host = strtolower($url->getHost());
        $allowed = collect($allowedDomains)->contains(
            fn (string $domain): bool => $host === $domain || str_ends_with($host, '.'.$domain)
        );

        if (! $allowed || ! in_array(strtolower($url->getScheme()), ['http', 'https'], true)) {
            throw new InvalidArgumentException('The URL must belong to YouTube.');
        }

        return $url;
    }

    private function request(): PendingRequest
    {
        $apiKey = trim((string) config('youtube.drivers.google.api_key'));

        if ($apiKey === '') {
            throw new RuntimeException('YouTube API is not configured.');
        }

        return Http::baseUrl(rtrim((string) config('youtube.drivers.google.base_url'), '/'))
            ->acceptJson()
            ->withHeaders(['X-Goog-Api-Key' => $apiKey])
            ->timeout(max(1, (int) config('youtube.drivers.google.timeout', 10)));
    }

    private function bestThumbnail(mixed $thumbnails): ?string
    {
        if (! is_array($thumbnails)) {
            return null;
        }

        foreach (['maxres', 'standard', 'high', 'medium', 'default'] as $size) {
            $url = $thumbnails[$size]['url'] ?? null;

            if (is_string($url) && trim($url) !== '') {
                return trim($url);
            }
        }

        return null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function errorMessage(string $resource, int $status, mixed $payload): string
    {
        $message = is_array($payload) ? data_get($payload, 'error.message') : null;

        return sprintf(
            'YouTube could not retrieve the %s (%d)%s.',
            $resource,
            $status,
            is_string($message) && $message !== '' ? ': '.$message : ''
        );
    }
}
