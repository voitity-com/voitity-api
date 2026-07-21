<?php

namespace App\Classes\UsdCopRateService;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class UsdCopRateService
{
    public function __construct(private readonly UsdCopRateManager $manager) {}

    public function current(bool $forceRefresh = false): UsdCopRate
    {
        if (! $forceRefresh) {
            $cached = $this->rateFromCache($this->cacheKey());

            if ($cached instanceof UsdCopRate) {
                return $cached;
            }
        }

        return $this->refresh();
    }

    public function refresh(): UsdCopRate
    {
        try {
            return $this->storeFreshRate($this->freshRateFromDriver($this->primaryDriver()));
        } catch (Throwable $primaryException) {
            $fallbackDriver = $this->fallbackDriver();

            if ($fallbackDriver && $fallbackDriver !== $this->primaryDriver()) {
                try {
                    return $this->storeFreshRate($this->freshRateFromDriver($fallbackDriver));
                } catch (Throwable $fallbackException) {
                    $this->logRefreshFailure($primaryException, $fallbackException);
                }
            } else {
                $this->logRefreshFailure($primaryException);
            }
        }

        $lastKnown = $this->rateFromCache($this->lastKnownCacheKey());

        if ($lastKnown instanceof UsdCopRate) {
            $stale = $lastKnown->markStale();
            $this->storeTemporaryStaleRate($stale);

            return $stale;
        }

        return $this->storeFreshRate($this->freshRateFromDriver('config'));
    }

    public function syncConfig(bool $forceRefresh = false): UsdCopRate
    {
        $rate = $this->current($forceRefresh);

        config(['payment.usd_cop_rate' => $rate->value]);

        return $rate;
    }

    /**
     * @return array<string, mixed>
     */
    public function responseData(?UsdCopRate $rate = null): array
    {
        $rate ??= $this->current();

        return [
            'pair' => 'USD/COP',
            'base_currency' => 'USD',
            'quote_currency' => 'COP',
            'rate' => $rate->value,
            'source' => $rate->source,
            'unit' => $rate->unit,
            'valid_from' => $rate->validFrom,
            'valid_to' => $rate->validTo,
            'fetched_at' => $rate->fetchedAt,
            'stale' => $rate->stale,
            'cache_ttl_seconds' => $this->cacheTtlSeconds(),
        ];
    }

    private function freshRateFromDriver(string $driver): UsdCopRate
    {
        $rate = $this->manager->driver($driver)->latest()->fresh();
        $this->validate($rate);

        return $rate;
    }

    private function storeFreshRate(UsdCopRate $rate): UsdCopRate
    {
        Cache::put($this->cacheKey(), $rate->toArray(), $this->cacheTtlSeconds());
        Cache::forever($this->lastKnownCacheKey(), $rate->toArray());

        return $rate;
    }

    private function storeTemporaryStaleRate(UsdCopRate $rate): void
    {
        Cache::put($this->cacheKey(), $rate->toArray(), $this->staleCacheTtlSeconds());
    }

    private function rateFromCache(string $key): ?UsdCopRate
    {
        $value = Cache::get($key);

        if (! is_array($value)) {
            return null;
        }

        $rate = UsdCopRate::fromArray($value);

        return $rate->value > 0 ? $rate : null;
    }

    private function validate(UsdCopRate $rate): void
    {
        $min = (float) config('payment.exchange_rates.usd_cop.min', 2000);
        $max = (float) config('payment.exchange_rates.usd_cop.max', 8000);

        if ($rate->value <= 0) {
            throw new InvalidArgumentException('USD to COP exchange rate must be greater than zero.');
        }

        if ($rate->value < $min || $rate->value > $max) {
            throw new InvalidArgumentException("USD to COP exchange rate {$rate->value} is outside the accepted range.");
        }

        if (strtoupper($rate->unit) !== 'COP') {
            throw new InvalidArgumentException("USD to COP exchange rate unit must be COP, {$rate->unit} received.");
        }
    }

    private function primaryDriver(): string
    {
        return (string) config('payment.exchange_rates.usd_cop.driver', 'datos_gov');
    }

    private function fallbackDriver(): ?string
    {
        $driver = config('payment.exchange_rates.usd_cop.fallback_driver', 'dolar_api');

        return is_string($driver) && trim($driver) !== '' ? trim($driver) : null;
    }

    private function cacheTtlSeconds(): int
    {
        return max(1, (int) config('payment.usd_cop_rate_cache_ttl', 14400));
    }

    private function staleCacheTtlSeconds(): int
    {
        return max(1, (int) config('payment.exchange_rates.usd_cop.stale_cache_ttl', 300));
    }

    private function cacheKey(): string
    {
        return (string) config('payment.exchange_rates.usd_cop.cache_key', 'payments:usd_cop_rate:current');
    }

    private function lastKnownCacheKey(): string
    {
        return (string) config('payment.exchange_rates.usd_cop.last_known_cache_key', 'payments:usd_cop_rate:last_known');
    }

    private function logRefreshFailure(Throwable $primaryException, ?Throwable $fallbackException = null): void
    {
        Log::warning('Unable to refresh USD to COP exchange rate.', [
            'primary_driver' => $this->primaryDriver(),
            'fallback_driver' => $this->fallbackDriver(),
            'primary_error' => $primaryException->getMessage(),
            'fallback_error' => $fallbackException?->getMessage(),
        ]);
    }
}
