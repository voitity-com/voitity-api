<?php

namespace Tests\Unit\Providers;

use App\Classes\YouTubeService\Google\GoogleYouTubeClient;
use App\Classes\YouTubeService\YouTubeClient;
use App\Classes\YouTubeService\YouTubeManager;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class YouTubeServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['youtube.default' => 'google']);
        app(YouTubeManager::class)->forgetDrivers();
    }

    #[Test]
    public function it_resolves_the_manager_as_a_singleton(): void
    {
        $this->assertSame(app(YouTubeManager::class), app(YouTubeManager::class));
    }

    #[Test]
    public function it_resolves_the_configured_client_contract(): void
    {
        $client = app(YouTubeClient::class);

        $this->assertInstanceOf(YouTubeClient::class, $client);
        $this->assertInstanceOf(GoogleYouTubeClient::class, $client);
    }
}
