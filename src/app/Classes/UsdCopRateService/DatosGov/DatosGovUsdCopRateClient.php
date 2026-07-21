<?php

namespace App\Classes\UsdCopRateService\DatosGov;

use App\Classes\UsdCopRateService\UsdCopRate;
use App\Classes\UsdCopRateService\UsdCopRateClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class DatosGovUsdCopRateClient implements UsdCopRateClient
{
    public function __construct(
        private readonly ?string $baseUrl = 'https://www.datos.gov.co',
        private readonly ?string $resourceId = '32sa-8pi3',
        private readonly ?int $timeout = 5,
    ) {}

    public function latest(): UsdCopRate
    {
        $url = $this->normalizedBaseUrl().'/resource/'.$this->normalizedResourceId().'.json';
        $response = Http::acceptJson()
            ->timeout(max(1, (int) ($this->timeout ?? 5)))
            ->get($url, [
                '$limit' => 1,
                '$order' => 'vigenciadesde DESC',
            ]);

        if (! $response->successful()) {
            throw new InvalidArgumentException('Datos.gov.co did not return a successful USD to COP rate response.');
        }

        $data = $response->json();
        $row = is_array($data) && is_array($data[0] ?? null) ? $data[0] : null;

        if (! is_array($row)) {
            throw new InvalidArgumentException('Datos.gov.co USD to COP rate response is empty.');
        }

        $value = $this->numberOrNull($row['valor'] ?? null);

        if ($value === null) {
            throw new InvalidArgumentException('Datos.gov.co USD to COP rate response does not include a numeric value.');
        }

        return new UsdCopRate(
            value: $value,
            source: 'datos_gov',
            unit: (string) ($row['unidad'] ?? 'COP'),
            validFrom: isset($row['vigenciadesde']) ? (string) $row['vigenciadesde'] : null,
            validTo: isset($row['vigenciahasta']) ? (string) $row['vigenciahasta'] : null,
            fetchedAt: Carbon::now()->toJSON(),
        );
    }

    private function normalizedBaseUrl(): string
    {
        $baseUrl = trim((string) ($this->baseUrl ?: 'https://www.datos.gov.co'));

        if ($baseUrl === '') {
            return 'https://www.datos.gov.co';
        }

        return rtrim($baseUrl, '/');
    }

    private function normalizedResourceId(): string
    {
        $resourceId = trim((string) ($this->resourceId ?: '32sa-8pi3'));

        if ($resourceId === '') {
            return '32sa-8pi3';
        }

        return trim($resourceId, '/');
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
