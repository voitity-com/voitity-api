<?php

namespace Tests\Unit\Providers;

use App\Classes\VideoAIService\VideoAIClient;
use App\Classes\VideoAIService\VideoAIManager;
use App\Classes\VideoAIService\VideoAIService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VideoAIServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('videoai.default', 'runway');
        Config::set('videoai.drivers.runway.api_key', 'test-api-key');
        Config::set('videoai.drivers.runway.base_url', 'https://runway.test');
        Config::set('videoai.drivers.runway.api_version', '2024-11-06');
        Config::set('videoai.drivers.runway.image_model', 'gen4_image');
        Config::set('videoai.drivers.runway.video_model', 'gen4.5');
        Config::set('videoai.drivers.runway.reference_image_tag', 'base_image');
        Config::set('videoai.drivers.runway.default_image_ratio', '1024:1024');
        Config::set('videoai.drivers.runway.default_video_ratio', '960:960');
        Config::set('videoai.drivers.runway.default_duration', 5);

        app(VideoAIManager::class)->forgetDrivers();
    }

    #[Test]
    public function it_can_resolve_video_ai_manager(): void
    {
        $manager = app(VideoAIManager::class);

        $this->assertInstanceOf(VideoAIManager::class, $manager);
    }

    #[Test]
    public function it_can_resolve_video_ai_client(): void
    {
        $client = app(VideoAIClient::class);

        $this->assertInstanceOf(VideoAIClient::class, $client);
    }

    #[Test]
    public function it_can_resolve_video_ai_service(): void
    {
        $service = app(VideoAIService::class);

        $this->assertInstanceOf(VideoAIService::class, $service);
        $this->assertInstanceOf(VideoAIClient::class, $service->getVideoAIClient());
    }

    #[Test]
    public function video_ai_manager_is_singleton(): void
    {
        $manager1 = app(VideoAIManager::class);
        $manager2 = app(VideoAIManager::class);

        $this->assertSame($manager1, $manager2);
    }
}
