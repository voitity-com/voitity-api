<?php

namespace Tests\Unit\Providers;

use App\Classes\PaymentService\PaymentClient;
use App\Classes\PaymentService\PaymentManager;
use App\Classes\PaymentService\PaymentService;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('payment.default', 'wompi');
        Config::set('payment.drivers.wompi.public_key', 'pub_test_key');
        Config::set('payment.drivers.wompi.private_key', 'prv_test_key');
        Config::set('payment.drivers.wompi.integrity_secret', 'test_integrity_key');
        Config::set('payment.drivers.wompi.events_secret', 'test_events_key');
        Config::set('payment.drivers.wompi.checkout_url', 'https://checkout.wompi.co/p/');
        Config::set('payment.drivers.wompi.widget_url', 'https://checkout.wompi.co/widget.js');

        app(PaymentManager::class)->forgetDrivers();
    }

    #[Test]
    public function it_can_resolve_payment_manager(): void
    {
        $manager = app(PaymentManager::class);

        $this->assertInstanceOf(PaymentManager::class, $manager);
    }

    #[Test]
    public function it_can_resolve_payment_client(): void
    {
        $client = app(PaymentClient::class);

        $this->assertInstanceOf(PaymentClient::class, $client);
    }

    #[Test]
    public function it_can_resolve_payment_service(): void
    {
        $service = app(PaymentService::class);

        $this->assertInstanceOf(PaymentService::class, $service);
        $this->assertInstanceOf(PaymentClient::class, $service->getPaymentClient());
    }

    #[Test]
    public function payment_manager_is_singleton(): void
    {
        $manager1 = app(PaymentManager::class);
        $manager2 = app(PaymentManager::class);

        $this->assertSame($manager1, $manager2);
    }
}
