<?php

namespace Tests\Unit\Classes\YouTubeService;

use App\Classes\YouTubeService\Google\GoogleYouTubeClient;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class GoogleYouTubeClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'youtube.drivers.google.api_key' => 'youtube-test-key',
            'youtube.drivers.google.base_url' => 'https://www.googleapis.com/youtube/v3',
            'youtube.drivers.google.timeout' => 5,
        ]);
    }

    #[Test]
    public function it_maps_channels_and_public_embeddable_videos(): void
    {
        Http::fake([
            'https://www.googleapis.com/youtube/v3/channels*' => Http::response([
                'items' => [[
                    'id' => 'UC1234567890123456789012',
                    'snippet' => [
                        'customUrl' => '@bigmelo',
                        'title' => 'Bigmelo',
                        'thumbnails' => ['high' => ['url' => 'https://example.com/channel.jpg']],
                    ],
                ]],
            ]),
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [[
                    'id' => 'dQw4w9WgXcQ',
                    'snippet' => [
                        'channelId' => 'UC1234567890123456789012',
                        'channelTitle' => 'Bigmelo',
                        'publishedAt' => '2026-08-01T12:00:00Z',
                        'title' => 'Demo video',
                        'thumbnails' => ['maxres' => ['url' => 'https://example.com/video.jpg']],
                    ],
                    'status' => ['embeddable' => true, 'privacyStatus' => 'public'],
                ]],
            ]),
        ]);

        $client = new GoogleYouTubeClient;
        $channel = $client->getChannel('https://www.youtube.com/@bigmelo');
        $video = $client->getVideo('https://youtu.be/dQw4w9WgXcQ');

        $this->assertSame('UC1234567890123456789012', $channel->id);
        $this->assertSame('https://www.youtube.com/@bigmelo', $channel->url);
        $this->assertSame('dQw4w9WgXcQ', $video->id);
        $this->assertSame('https://example.com/video.jpg', $video->thumbnailUrl);
        $this->assertTrue($video->isAccessible());
        Http::assertSent(fn ($request): bool => $request->hasHeader('X-Goog-Api-Key', 'youtube-test-key'));
    }

    #[Test]
    public function it_rejects_non_youtube_urls_before_sending_a_request(): void
    {
        Http::fake();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The URL must belong to YouTube.');

        (new GoogleYouTubeClient)->getVideo('https://example.com/watch?v=dQw4w9WgXcQ');
    }

    #[Test]
    public function it_maps_provider_failures_without_exposing_the_api_key(): void
    {
        Http::fake([
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
                'error' => ['message' => 'quota exceeded'],
            ], 403),
        ]);

        try {
            (new GoogleYouTubeClient)->getVideo('dQw4w9WgXcQ');
            $this->fail('Expected a provider exception.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('quota exceeded', $exception->getMessage());
            $this->assertStringNotContainsString('youtube-test-key', $exception->getMessage());
        }
    }

    #[Test]
    public function it_requires_an_api_key(): void
    {
        config(['youtube.drivers.google.api_key' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('YouTube API is not configured.');

        (new GoogleYouTubeClient)->getVideo('dQw4w9WgXcQ');
    }
}
