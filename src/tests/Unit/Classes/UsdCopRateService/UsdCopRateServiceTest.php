<?php

namespace Tests\Unit\Classes\UsdCopRateService;

use App\Classes\UsdCopRateService\UsdCopRateManager;
use App\Classes\UsdCopRateService\UsdCopRateService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UsdCopRateServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Carbon::setTestNow('2026-07-21 10:00:00');
        Config::set('payment.exchange_rates.usd_cop.driver', 'datos_gov');
        Config::set('payment.exchange_rates.usd_cop.fallback_driver', 'dolar_api');
        Config::set('payment.exchange_rates.usd_cop.min', 2000);
        Config::set('payment.exchange_rates.usd_cop.max', 8000);
        Config::set('payment.usd_cop_rate_cache_ttl', 14400);
        app(UsdCopRateManager::class)->forgetDrivers();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function it_uses_the_cached_rate_until_the_ttl_expires(): void
    {
        Http::fake([
            'https://www.datos.gov.co/resource/32sa-8pi3.json*' => Http::sequence()
                ->push([
                    [
                        'valor' => '3262.58',
                        'unidad' => 'COP',
                        'vigenciadesde' => '2026-07-18T00:00:00.000',
                        'vigenciahasta' => '2026-07-21T00:00:00.000',
                    ],
                ])
                ->push([
                    [
                        'valor' => '3301.25',
                        'unidad' => 'COP',
                        'vigenciadesde' => '2026-07-22T00:00:00.000',
                        'vigenciahasta' => '2026-07-23T00:00:00.000',
                    ],
                ]),
        ]);

        $service = app(UsdCopRateService::class);

        $first = $service->current();
        $cached = $service->current();

        $this->assertSame(3262.58, $first->value);
        $this->assertSame(3262.58, $cached->value);
        Http::assertSentCount(1);

        Carbon::setTestNow('2026-07-21 14:00:01');

        $refreshed = $service->current();

        $this->assertSame(3301.25, $refreshed->value);
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_refreshes_when_the_cache_is_empty(): void
    {
        Http::fake([
            'https://www.datos.gov.co/resource/32sa-8pi3.json*' => Http::sequence()
                ->push([['valor' => '3262.58', 'unidad' => 'COP']])
                ->push([['valor' => '3301.25', 'unidad' => 'COP']]),
        ]);

        $service = app(UsdCopRateService::class);

        $this->assertSame(3262.58, $service->current()->value);

        Cache::forget('payments:usd_cop_rate:current');

        $this->assertSame(3301.25, $service->current()->value);
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_falls_back_to_the_configured_rate_when_external_providers_fail(): void
    {
        Config::set('payment.exchange_rates.usd_cop.fallback_driver', 'config');
        Config::set('payment.usd_cop_rate_drivers.config.rate', 3900);
        app(UsdCopRateManager::class)->forgetDrivers();

        Http::fake([
            'https://www.datos.gov.co/*' => Http::response([], 503),
        ]);

        $rate = app(UsdCopRateService::class)->current();

        $this->assertSame(3900.0, $rate->value);
        $this->assertSame('config', $rate->source);
        $this->assertFalse($rate->stale);
    }

    #[Test]
    public function it_returns_the_last_known_rate_as_stale_when_refresh_fails(): void
    {
        Cache::forever('payments:usd_cop_rate:last_known', [
            'value' => 3262.58,
            'source' => 'datos_gov',
            'unit' => 'COP',
            'valid_from' => '2026-07-18T00:00:00.000',
            'valid_to' => '2026-07-21T00:00:00.000',
            'fetched_at' => '2026-07-21T10:00:00.000000Z',
            'stale' => false,
        ]);

        Http::fake([
            'https://www.datos.gov.co/*' => Http::response([], 503),
            'https://co.dolarapi.com/*' => Http::response([], 503),
        ]);

        $rate = app(UsdCopRateService::class)->current();

        $this->assertSame(3262.58, $rate->value);
        $this->assertSame('datos_gov', $rate->source);
        $this->assertTrue($rate->stale);
    }

    #[Test]
    public function it_syncs_the_rate_into_laravel_config(): void
    {
        Http::fake([
            'https://www.datos.gov.co/resource/32sa-8pi3.json*' => Http::response([
                [
                    'valor' => '3262.58',
                    'unidad' => 'COP',
                ],
            ]),
        ]);

        $rate = app(UsdCopRateService::class)->syncConfig();

        $this->assertSame(3262.58, $rate->value);
        $this->assertSame(3262.58, config('payment.usd_cop_rate'));
    }
}
