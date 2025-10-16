<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\ChatAIService;

use App\Classes\ChatAIService\ChatAIClient;
use App\Classes\ChatAIService\ChatAIManager;
use App\Classes\ChatAIService\OpenAI\OpenAIClient;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChatAIManagerTest extends TestCase
{
    private ChatAIManager $chatAIManager;
    private MockInterface $mockConfig;
    /** @var MockInterface&Container */
    private MockInterface $mockContainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockConfig = Mockery::mock(Config::class);
        $this->mockContainer = Mockery::mock(Container::class);

        $this->mockContainer->shouldReceive('make')
            ->with('config')
            ->andReturn($this->mockConfig);

        $this->chatAIManager = new ChatAIManager($this->mockContainer);
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
            ->with('chatai.default', 'openai')
            ->andReturn('openai');

        $this->assertSame('openai', $this->chatAIManager->getDefaultDriver());
    }

    #[Test]
    public function it_returns_custom_default_driver_from_config(): void
    {
        $this->mockConfig->shouldReceive('get')
            ->once()
            ->with('chatai.default', 'openai')
            ->andReturn('anthropic');

        $this->assertSame('anthropic', $this->chatAIManager->getDefaultDriver());
    }

    #[Test]
    public function it_creates_openai_driver_instance(): void
    {
        $this->mockConfig->shouldReceive('get')
            ->with('chatai.drivers.openai', [])
            ->andReturn([
                'api_key' => 'test-api-key',
                'base_url' => 'https://example.test/v1',
                'default_model' => 'gpt-test',
                'whisper_model' => 'whisper-test',
            ]);

        $driver = $this->chatAIManager->createOpenaiDriver();

        $this->assertInstanceOf(OpenAIClient::class, $driver);
        $this->assertInstanceOf(ChatAIClient::class, $driver);
    }

    #[Test]
    public function it_can_get_default_driver_instance(): void
    {
        $this->mockConfig->shouldReceive('get')
            ->with('chatai.default', 'openai')
            ->andReturn('openai');

        $this->mockConfig->shouldReceive('get')
            ->with('chatai.drivers.openai', [])
            ->andReturn([]);

        $driver = $this->chatAIManager->driver();

        $this->assertInstanceOf(ChatAIClient::class, $driver);
        $this->assertInstanceOf(OpenAIClient::class, $driver);
    }

    #[Test]
    public function it_can_get_specific_driver_instance(): void
    {
        $this->mockConfig->shouldReceive('get')
            ->with('chatai.drivers.openai', [])
            ->andReturn([]);

        $driver = $this->chatAIManager->driver('openai');

        $this->assertInstanceOf(ChatAIClient::class, $driver);
        $this->assertInstanceOf(OpenAIClient::class, $driver);
    }

    #[Test]
    public function it_creates_custom_driver_with_valid_config(): void
    {
        $mockClient = Mockery::mock(ChatAIClient::class);
        $customCallable = function () use ($mockClient) {
            return $mockClient;
        };

        $config = ['via' => $customCallable];

        $this->mockContainer->shouldReceive('call')
            ->once()
            ->with($customCallable)
            ->andReturn($mockClient);

        $reflection = new \ReflectionClass($this->chatAIManager);
        $method = $reflection->getMethod('createCustomDriver');
        $method->setAccessible(true);

        $result = $method->invoke($this->chatAIManager, $config);

        $this->assertInstanceOf(ChatAIClient::class, $result);
        $this->assertSame($mockClient, $result);
    }

    #[Test]
    public function it_throws_exception_when_custom_driver_config_missing_via(): void
    {
        $config = ['foo' => 'bar'];

        $reflection = new \ReflectionClass($this->chatAIManager);
        $method = $reflection->getMethod('createCustomDriver');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom chat AI driver must specify a "via" callable.');

        $method->invoke($this->chatAIManager, $config);
    }
}
