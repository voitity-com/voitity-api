<?php

namespace Tests\Unit\Classes\ProfileKnowledgeAIService;

use App\Classes\ProfileKnowledgeAIService\Local\LocalProfileKnowledgeClient;
use App\Classes\ProfileKnowledgeAIService\OpenAI\OpenAIProfileKnowledgeClient;
use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeAIClient;
use App\Classes\ProfileKnowledgeAIService\ProfileKnowledgeAIManager;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileKnowledgeAIManagerTest extends TestCase
{
    private ProfileKnowledgeAIManager $manager;

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

        $this->manager = new ProfileKnowledgeAIManager($this->mockContainer);
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
            ->with('profile-knowledge-ai.default', 'openai')
            ->andReturn('openai');

        $this->assertSame('openai', $this->manager->getDefaultDriver());
    }

    #[Test]
    public function it_creates_openai_and_local_drivers(): void
    {
        $this->mockConfig->shouldReceive('get')
            ->with('profile-knowledge-ai.drivers.openai', [])
            ->andReturn([
                'api_key' => 'test-key',
                'base_url' => 'https://fake-openai.test/v1',
                'default_model' => 'gpt-test',
                'max_tokens' => 1000,
                'temperature' => 0.1,
            ]);

        $this->assertInstanceOf(OpenAIProfileKnowledgeClient::class, $this->manager->createOpenaiDriver());
        $this->assertInstanceOf(LocalProfileKnowledgeClient::class, $this->manager->createLocalDriver());
    }

    #[Test]
    public function it_can_resolve_default_driver(): void
    {
        $this->mockConfig->shouldReceive('get')
            ->with('profile-knowledge-ai.default', 'openai')
            ->andReturn('local');

        $driver = $this->manager->driver();

        $this->assertInstanceOf(ProfileKnowledgeAIClient::class, $driver);
        $this->assertInstanceOf(LocalProfileKnowledgeClient::class, $driver);
    }

    #[Test]
    public function it_creates_custom_driver_with_valid_config(): void
    {
        $mockClient = Mockery::mock(ProfileKnowledgeAIClient::class);
        $customCallable = function () use ($mockClient) {
            return $mockClient;
        };

        $this->mockContainer->shouldReceive('call')
            ->once()
            ->with($customCallable)
            ->andReturn($mockClient);

        $reflection = new \ReflectionClass($this->manager);
        $method = $reflection->getMethod('createCustomDriver');
        $method->setAccessible(true);

        $result = $method->invoke($this->manager, ['via' => $customCallable]);

        $this->assertInstanceOf(ProfileKnowledgeAIClient::class, $result);
        $this->assertSame($mockClient, $result);
    }

    #[Test]
    public function it_throws_exception_when_custom_driver_config_missing_via(): void
    {
        $reflection = new \ReflectionClass($this->manager);
        $method = $reflection->getMethod('createCustomDriver');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom profile knowledge AI driver must specify a "via" callable.');

        $method->invoke($this->manager, []);
    }
}
