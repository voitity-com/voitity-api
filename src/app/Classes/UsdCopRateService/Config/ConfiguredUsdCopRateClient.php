<?php

namespace App\Classes\UsdCopRateService\Config;

use App\Classes\UsdCopRateService\UsdCopRate;
use App\Classes\UsdCopRateService\UsdCopRateClient;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class ConfiguredUsdCopRateClient implements UsdCopRateClient
{
    public function __construct(private readonly ?float $rate = null) {}

    public function latest(): UsdCopRate
    {
        $rate = (float) ($this->rate ?? config('payment.usd_cop_rate', 4000));

        if ($rate <= 0) {
            throw new InvalidArgumentException('Configured USD to COP exchange rate must be greater than zero.');
        }

        return new UsdCopRate(
            value: $rate,
            source: 'config',
            unit: 'COP',
            fetchedAt: Carbon::now()->toJSON(),
        );
    }
}
