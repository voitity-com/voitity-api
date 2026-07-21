<?php

namespace App\Classes\UsdCopRateService;

use App\Classes\UsdCopRateService\Config\ConfiguredUsdCopRateClient;
use App\Classes\UsdCopRateService\DatosGov\DatosGovUsdCopRateClient;
use App\Classes\UsdCopRateService\DolarApi\DolarApiUsdCopRateClient;
use Illuminate\Support\Manager;
use InvalidArgumentException;

class UsdCopRateManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('payment.exchange_rates.usd_cop.driver', 'datos_gov');
    }

    public function createDatosGovDriver(): UsdCopRateClient
    {
        $config = $this->config->get('payment.usd_cop_rate_drivers.datos_gov', []);

        return new DatosGovUsdCopRateClient(
            baseUrl: $config['base_url'] ?? null,
            resourceId: $config['resource_id'] ?? null,
            timeout: isset($config['timeout']) ? (int) $config['timeout'] : null,
        );
    }

    public function createDolarApiDriver(): UsdCopRateClient
    {
        $config = $this->config->get('payment.usd_cop_rate_drivers.dolar_api', []);

        return new DolarApiUsdCopRateClient(
            url: $config['url'] ?? null,
            timeout: isset($config['timeout']) ? (int) $config['timeout'] : null,
        );
    }

    public function createConfigDriver(): UsdCopRateClient
    {
        $config = $this->config->get('payment.usd_cop_rate_drivers.config', []);
        $rate = $config['rate'] ?? $this->config->get('payment.exchange_rates.usd_cop.manual_rate');

        return new ConfiguredUsdCopRateClient(
            rate: isset($rate) ? (float) $rate : null,
        );
    }

    public function driver($driver = null): UsdCopRateClient
    {
        return parent::driver($driver);
    }

    /**
     * @param  array{via:mixed}  $config
     */
    protected function createCustomDriver(array $config): UsdCopRateClient
    {
        if (! isset($config['via'])) {
            throw new InvalidArgumentException('Custom USD to COP rate driver must specify a "via" callable.');
        }

        return $this->container->call($config['via']);
    }
}
