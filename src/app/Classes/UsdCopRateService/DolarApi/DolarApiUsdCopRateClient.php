<?php

namespace App\Classes\UsdCopRateService\DolarApi;

use App\Classes\UsdCopRateService\UsdCopRate;
use App\Classes\UsdCopRateService\UsdCopRateClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class DolarApiUsdCopRateClient implements UsdCopRateClient
{
    public function __construct(
        private readonly ?string $url = 'https://co.dolarapi.com/v1/trm',
        private readonly ?int $timeout = 5,
    ) {}

    public function latest(): UsdCopRate
    {
        $response = Http::acceptJson()
            ->timeout(max(1, (int) ($this->timeout ?? 5)))
            ->get($this->normalizedUrl());

        if (! $response->successful()) {
            throw new InvalidArgumentException('DolarApi did not return a successful USD to COP rate response.');
        }

        $data = $response->json();

        if (! is_array($data)) {
            throw new InvalidArgumentException('DolarApi USD to COP rate response is invalid.');
        }

        $value = $this->numberOrNull($data['valor'] ?? null);

        if ($value === null) {
            throw new InvalidArgumentException('DolarApi USD to COP rate response does not include a numeric value.');
        }

        return new UsdCopRate(
            value: $value,
            source: 'dolar_api',
            unit: (string) ($data['unidad'] ?? 'COP'),
            validFrom: isset($data['fechaActualizacion']) ? (string) $data['fechaActualizacion'] : null,
            fetchedAt: Carbon::now()->toJSON(),
        );
    }

    private function normalizedUrl(): string
    {
        $url = trim((string) ($this->url ?: 'https://co.dolarapi.com/v1/trm'));

        if ($url === '') {
            return 'https://co.dolarapi.com/v1/trm';
        }

        return $url;
    }

    private function numberOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
