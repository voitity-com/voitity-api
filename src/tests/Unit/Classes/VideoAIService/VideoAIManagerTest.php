<?php

namespace Tests\Unit\Classes\VideoAIService;

use App\Classes\VideoAIService\Runway\RunwayVideoAI;
use App\Classes\VideoAIService\VideoAIClient;
use App\Classes\VideoAIService\VideoAIManager;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VideoAIManagerTest extends TestCase
{
    private VideoAIManager $videoAIManager;
    private MockInterface $mockConfig;
    private MockInterface $mockContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockConfig = Mockery::mock(Config::class);
        $this->mockContainer = Mockery::mock(Container::class);

        $this->mockContainer->shouldReceive('make')
            ->with('config')
            ->andReturn($this->mockConfig);

        $this->videoAIManager = new VideoAIManager($this->mockContainer);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_returns_default_driver_name_from_config(): void
    {
        $this->mockConfig->shouldReceive('get')
            ->once()
            ->with('videoai.default', 'runway')
            ->andReturn('runway');

        $this->assertSame('runway', $this->videoAIManager->getDefaultDriver());
    }

    #[Test]
    public function it_creates_runway_driver_instance(): void
    {
        $this->mockConfig->shouldReceive('get')
            ->with('videoai.drivers.runway', [])
            ->andReturn($this->runwayConfig());

        $driver = $this->videoAIManager->createRunwayDriver();

        $this->assertInstanceOf(RunwayVideoAI::class, $driver);
        $this->assertInstanceOf(VideoAIClient::class, $driver);
    }

    #[Test]
    public function it_can_get_default_driver_instance(): void
    {
        $this->mockConfig->shouldReceive('get')
            ->with('videoai.default', 'runway')
            ->andReturn('runway');

        $this->mockConfig->shouldReceive('get')
            ->with('videoai.drivers.runway', [])
            ->andReturn($this->runwayConfig());

        $driver = $this->videoAIManager->driver();

        $this->assertInstanceOf(VideoAIClient::class, $driver);
        $this->assertInstanceOf(RunwayVideoAI::class, $driver);
    }

    #[Test]
    public function it_creates_custom_driver_with_valid_config(): void
    {
        $mockClient = Mockery::mock(VideoAIClient::class);
        $customCallable = function () use ($mockClient) {
            return $mockClient;
        };

        $this->mockContainer->shouldReceive('call')
            ->once()
            ->with($customCallable)
            ->andReturn($mockClient);

        $reflection = new \ReflectionClass($this->videoAIManager);
        $method = $reflection->getMethod('createCustomDriver');
        $method->setAccessible(true);

        $result = $method->invoke($this->videoAIManager, ['via' => $customCallable]);

        $this->assertInstanceOf(VideoAIClient::class, $result);
        $this->assertSame($mockClient, $result);
    }

    #[Test]
    public function it_throws_exception_when_custom_driver_config_missing_via(): void
    {
        $reflection = new \ReflectionClass($this->videoAIManager);
        $method = $reflection->getMethod('createCustomDriver');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom video AI driver must specify a "via" callable.');

        $method->invoke($this->videoAIManager, []);
    }

    private function runwayConfig(): array
    {
        return [
            'api_key' => 'test-api-key',
            'base_url' => 'https://runway.test',
            'api_version' => '2024-11-06',
            'image_model' => 'gen4_image',
            'video_model' => 'gen4.5',
            'reference_image_tag' => 'base_image',
            'default_image_ratio' => '1024:1024',
            'default_video_ratio' => '960:960',
            'default_duration' => 5,
        ];
    }
}
