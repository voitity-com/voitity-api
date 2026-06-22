<?php

namespace App\Classes\PaymentService;

use App\Classes\PaymentService\Wompi\WompiPaymentClient;
use Illuminate\Support\Manager;
use InvalidArgumentException;

class PaymentManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('payment.default', 'wompi');
    }

    public function createWompiDriver(): PaymentClient
    {
        $config = $this->config->get('payment.drivers.wompi', []);

        return new WompiPaymentClient(
            publicKey: $config['public_key'] ?? null,
            integritySecret: $config['integrity_secret'] ?? null,
            eventsSecret: $config['events_secret'] ?? null,
            checkoutUrl: $config['checkout_url'] ?? null,
            widgetUrl: $config['widget_url'] ?? null,
            environment: $config['environment'] ?? null,
        );
    }

    public function driver($driver = null): PaymentClient
    {
        return parent::driver($driver);
    }

    /**
     * @param  array{via:mixed}  $config
     */
    protected function createCustomDriver(array $config): PaymentClient
    {
        if (! isset($config['via'])) {
            throw new InvalidArgumentException('Custom payment driver must specify a "via" callable.');
        }

        return $this->container->call($config['via']);
    }
}
