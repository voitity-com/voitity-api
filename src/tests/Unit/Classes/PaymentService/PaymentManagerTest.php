<?php

namespace Tests\Unit\Classes\PaymentService;

use App\Classes\PaymentService\PaymentClient;
use App\Classes\PaymentService\PaymentManager;
use App\Classes\PaymentService\Wompi\WompiPaymentClient;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentManagerTest extends TestCase
{
    private PaymentManager $paymentManager;

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

        $this->paymentManager = new PaymentManager($this->mockContainer);
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
            ->with('payment.default', 'wompi')
            ->andReturn('wompi');

        $this->assertSame('wompi', $this->paymentManager->getDefaultDriver());
    }

    #[Test]
    public function it_creates_wompi_driver_instance(): void
    {
        $this->mockConfig->shouldReceive('get')
            ->with('payment.drivers.wompi', [])
            ->andReturn($this->wompiConfig());

        $driver = $this->paymentManager->createWompiDriver();

        $this->assertInstanceOf(WompiPaymentClient::class, $driver);
        $this->assertInstanceOf(PaymentClient::class, $driver);
    }

    #[Test]
    public function it_can_get_default_driver_instance(): void
    {
        $this->mockConfig->shouldReceive('get')
            ->with('payment.default', 'wompi')
            ->andReturn('wompi');

        $this->mockConfig->shouldReceive('get')
            ->with('payment.drivers.wompi', [])
            ->andReturn($this->wompiConfig());

        $driver = $this->paymentManager->driver();

        $this->assertInstanceOf(PaymentClient::class, $driver);
        $this->assertInstanceOf(WompiPaymentClient::class, $driver);
    }

    #[Test]
    public function it_creates_custom_driver_with_valid_config(): void
    {
        $mockClient = Mockery::mock(PaymentClient::class);
        $customCallable = function () use ($mockClient) {
            return $mockClient;
        };

        $this->mockContainer->shouldReceive('call')
            ->once()
            ->with($customCallable)
            ->andReturn($mockClient);

        $reflection = new \ReflectionClass($this->paymentManager);
        $method = $reflection->getMethod('createCustomDriver');
        $method->setAccessible(true);

        $result = $method->invoke($this->paymentManager, ['via' => $customCallable]);

        $this->assertInstanceOf(PaymentClient::class, $result);
        $this->assertSame($mockClient, $result);
    }

    #[Test]
    public function it_throws_exception_when_custom_driver_config_missing_via(): void
    {
        $reflection = new \ReflectionClass($this->paymentManager);
        $method = $reflection->getMethod('createCustomDriver');
        $method->setAccessible(true);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Custom payment driver must specify a "via" callable.');

        $method->invoke($this->paymentManager, []);
    }

    /**
     * @return array<string, string>
     */
    private function wompiConfig(): array
    {
        return [
            'environment' => 'sandbox',
            'public_key' => 'pub_test_key',
            'private_key' => 'prv_test_key',
            'integrity_secret' => 'test_integrity_key',
            'events_secret' => 'test_events_key',
            'checkout_url' => 'https://checkout.wompi.co/p/',
            'widget_url' => 'https://checkout.wompi.co/widget.js',
        ];
    }
}
