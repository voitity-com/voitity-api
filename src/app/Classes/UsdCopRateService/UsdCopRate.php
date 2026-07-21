<?php

namespace App\Classes\UsdCopRateService;

use Illuminate\Support\Carbon;

class UsdCopRate
{
    public function __construct(
        public readonly float $value,
        public readonly string $source,
        public readonly string $unit = 'COP',
        public readonly ?string $validFrom = null,
        public readonly ?string $validTo = null,
        public readonly ?string $fetchedAt = null,
        public readonly bool $stale = false,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            value: (float) ($data['value'] ?? 0),
            source: (string) ($data['source'] ?? 'unknown'),
            unit: (string) ($data['unit'] ?? 'COP'),
            validFrom: isset($data['valid_from']) ? (string) $data['valid_from'] : null,
            validTo: isset($data['valid_to']) ? (string) $data['valid_to'] : null,
            fetchedAt: isset($data['fetched_at']) ? (string) $data['fetched_at'] : null,
            stale: (bool) ($data['stale'] ?? false),
        );
    }

    public function fresh(): self
    {
        return new self(
            value: $this->value,
            source: $this->source,
            unit: $this->unit,
            validFrom: $this->validFrom,
            validTo: $this->validTo,
            fetchedAt: $this->fetchedAt ?? Carbon::now()->toJSON(),
            stale: false,
        );
    }

    public function markStale(): self
    {
        return new self(
            value: $this->value,
            source: $this->source,
            unit: $this->unit,
            validFrom: $this->validFrom,
            validTo: $this->validTo,
            fetchedAt: $this->fetchedAt,
            stale: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'source' => $this->source,
            'unit' => $this->unit,
            'valid_from' => $this->validFrom,
            'valid_to' => $this->validTo,
            'fetched_at' => $this->fetchedAt,
            'stale' => $this->stale,
        ];
    }
}
