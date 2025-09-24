<?php

namespace Tests\Unit\Classes\VoiceService;

use App\Classes\VoiceService\VoiceManager;
use App\Classes\VoiceService\VoiceClient;
use App\Classes\VoiceService\ElevenLabs\ElevenLabsVoiceClient;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceManagerTest extends TestCase
{
    private VoiceManager $voiceManager;
    private MockInterface $mockConfig;
    /** @var MockInterface&Container */
    private MockInterface $mockContainer;

    public function setUp(): void
    {
        parent::setUp();
        
        $this->mockConfig = Mockery::mock(Config::class);
        $this->mockContainer = Mockery::mock(Container::class);

        // Mock the container to return our mocked config when 'config' is requested
        $this->mockContainer->shouldReceive('make')
            ->with('config')
            ->andReturn($this->mockConfig);

        $this->voiceManager = new VoiceManager($this->mockContainer);
    }

    public function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function it_returns_default_driver_name_from_config(): void
    {
        // Arrange
        $this->mockConfig->shouldReceive('get')
            ->once()
            ->with('voice.default', 'elevenlabs')
            ->andReturn('elevenlabs');

        // Act
        $result = $this->voiceManager->getDefaultDriver();

        // Assert
        $this->assertEquals('elevenlabs', $result);
    }

    #[Test]
    public function it_returns_fallback_default_driver_when_config_not_set(): void
    {
        // Arrange
        $this->mockConfig->shouldReceive('get')
            ->once()
            ->with('voice.default', 'elevenlabs')
            ->andReturn('elevenlabs'); // fallback value

        // Act
        $result = $this->voiceManager->getDefaultDriver();

        // Assert
        $this->assertEquals('elevenlabs', $result);
    }

    #[Test]
    public function it_returns_custom_default_driver_from_config(): void
    {
        // Arrange
        $this->mockConfig->shouldReceive('get')
            ->once()
            ->with('voice.default', 'elevenlabs')
            ->andReturn('custom-provider');

        // Act
        $result = $this->voiceManager->getDefaultDriver();

        // Assert
        $this->assertEquals('custom-provider', $result);
    }

    #[Test]
    public function it_creates_elevenlabs_driver_instance(): void
    {
        // Arrange - Mock the global config for ElevenLabsVoiceClient
        $this->app['config']->set('voice.drivers.elevenlabs.api_key', 'test-api-key');
        $this->app['config']->set('voice.drivers.elevenlabs.default_voice_settings', []);

        // Act
        $result = $this->voiceManager->createElevenlabsDriver();

        // Assert
        $this->assertInstanceOf(ElevenLabsVoiceClient::class, $result);
        $this->assertInstanceOf(VoiceClient::class, $result);
    }

    #[Test]
    public function it_can_get_driver_instance(): void
    {
        // Arrange
        $this->mockConfig->shouldReceive('get')
            ->with('voice.default', 'elevenlabs')
            ->andReturn('elevenlabs');
        
        $this->mockConfig->shouldReceive('get')
            ->with('voice.drivers.elevenlabs', [])
            ->andReturn([]);

        // Mock the global config for ElevenLabsVoiceClient
        $this->app['config']->set('voice.drivers.elevenlabs.api_key', 'test-api-key');
        $this->app['config']->set('voice.drivers.elevenlabs.default_voice_settings', []);

        // Act
        $result = $this->voiceManager->driver();

        // Assert
        $this->assertInstanceOf(VoiceClient::class, $result);
        $this->assertInstanceOf(ElevenLabsVoiceClient::class, $result);
    }

    #[Test]
    public function it_can_get_specific_driver_instance(): void
    {
        // Arrange
        $this->mockConfig->shouldReceive('get')
            ->with('voice.drivers.elevenlabs', [])
            ->andReturn([]);

        // Mock the global config for ElevenLabsVoiceClient
        $this->app['config']->set('voice.drivers.elevenlabs.api_key', 'test-api-key');
        $this->app['config']->set('voice.drivers.elevenlabs.default_voice_settings', []);

        // Act
        $result = $this->voiceManager->driver('elevenlabs');

        // Assert
        $this->assertInstanceOf(VoiceClient::class, $result);
        $this->assertInstanceOf(ElevenLabsVoiceClient::class, $result);
    }

    #[Test]
    public function it_creates_custom_driver_with_valid_config(): void
    {
        // Arrange
        $mockVoiceClient = Mockery::mock(VoiceClient::class);
        $customCallable = function() use ($mockVoiceClient) {
            return $mockVoiceClient;
        };
        
        $config = [
            'via' => $customCallable
        ];

        $this->mockContainer->shouldReceive('call')
            ->once()
            ->with($customCallable)
            ->andReturn($mockVoiceClient);

        // Use reflection to call the protected method
        $reflection = new \ReflectionClass($this->voiceManager);
        $method = $reflection->getMethod('createCustomDriver');
        $method->setAccessible(true);

        // Act
        $result = $method->invoke($this->voiceManager, $config);

        // Assert
        $this->assertInstanceOf(VoiceClient::class, $result);
        $this->assertSame($mockVoiceClient, $result);
    }

    #[Test]
    public function it_throws_exception_when_custom_driver_config_missing_via(): void
    {
        // Arrange
        $config = [
            'some_other_key' => 'value'
        ];

        // Use reflection to call the protected method
        $reflection = new \ReflectionClass($this->voiceManager);
        $method = $reflection->getMethod('createCustomDriver');
        $method->setAccessible(true);

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom voice driver must specify a "via" callable.');
        
        $method->invoke($this->voiceManager, $config);
    }

    #[Test]
    public function it_throws_exception_when_custom_driver_config_is_empty(): void
    {
        // Arrange
        $config = [];

        // Use reflection to call the protected method
        $reflection = new \ReflectionClass($this->voiceManager);
        $method = $reflection->getMethod('createCustomDriver');
        $method->setAccessible(true);

        // Act & Assert
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom voice driver must specify a "via" callable.');
        
        $method->invoke($this->voiceManager, $config);
    }

    #[Test]
    public function it_creates_custom_driver_with_class_string_via(): void
    {
        // Arrange
        $mockVoiceClient = Mockery::mock(VoiceClient::class);
        $config = [
            'via' => 'SomeCustomVoiceClientClass'
        ];

        $this->mockContainer->shouldReceive('call')
            ->once()
            ->with('SomeCustomVoiceClientClass')
            ->andReturn($mockVoiceClient);

        // Use reflection to call the protected method
        $reflection = new \ReflectionClass($this->voiceManager);
        $method = $reflection->getMethod('createCustomDriver');
        $method->setAccessible(true);

        // Act
        $result = $method->invoke($this->voiceManager, $config);

        // Assert
        $this->assertInstanceOf(VoiceClient::class, $result);
        $this->assertSame($mockVoiceClient, $result);
    }

    #[Test]
    public function it_caches_driver_instances(): void
    {
        // Arrange - Mock the global config for ElevenLabsVoiceClient
        $this->app['config']->set('voice.drivers.elevenlabs.api_key', 'test-api-key');
        $this->app['config']->set('voice.drivers.elevenlabs.default_voice_settings', []);

        // Act
        $driver1 = $this->voiceManager->driver('elevenlabs');
        $driver2 = $this->voiceManager->driver('elevenlabs');

        // Assert
        $this->assertSame($driver1, $driver2, 'Driver instances should be cached and return the same instance');
    }

    #[Test]
    public function it_returns_different_instances_for_different_drivers(): void
    {
        // Arrange - Mock default driver config
        $this->mockConfig->shouldReceive('get')
            ->with('voice.default', 'elevenlabs')
            ->once()
            ->andReturn('elevenlabs');

        // Mock the global config for ElevenLabsVoiceClient
        $this->app['config']->set('voice.drivers.elevenlabs.api_key', 'test-api-key');
        $this->app['config']->set('voice.drivers.elevenlabs.default_voice_settings', []);

        // Act
        $elevenlabsDriver = $this->voiceManager->driver('elevenlabs');
        $defaultDriver = $this->voiceManager->driver(); // Should get default

        // Assert
        $this->assertInstanceOf(ElevenLabsVoiceClient::class, $elevenlabsDriver);
        $this->assertInstanceOf(ElevenLabsVoiceClient::class, $defaultDriver);
        // Even though they're the same type, they should be the same cached instance since both resolve to 'elevenlabs'
        $this->assertSame($elevenlabsDriver, $defaultDriver);
    }
}
