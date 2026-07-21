<?php

namespace Tests\Unit\Classes\UsdCopRateService;

use App\Classes\UsdCopRateService\DatosGov\DatosGovUsdCopRateClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DatosGovUsdCopRateClientTest extends TestCase
{
    #[Test]
    public function it_parses_latest_trm_from_datos_gov(): void
    {
        Carbon::setTestNow('2026-07-21 10:00:00');

        Http::fake([
            'https://www.datos.gov.co/resource/32sa-8pi3.json*' => Http::response([
                [
                    'valor' => '3262.58',
                    'unidad' => 'COP',
                    'vigenciadesde' => '2026-07-18T00:00:00.000',
                    'vigenciahasta' => '2026-07-21T00:00:00.000',
                ],
            ]),
        ]);

        $rate = (new DatosGovUsdCopRateClient)->latest();

        $this->assertSame(3262.58, $rate->value);
        $this->assertSame('datos_gov', $rate->source);
        $this->assertSame('COP', $rate->unit);
        $this->assertSame('2026-07-18T00:00:00.000', $rate->validFrom);
        $this->assertSame('2026-07-21T00:00:00.000', $rate->validTo);
        $this->assertSame('2026-07-21T10:00:00.000000Z', $rate->fetchedAt);

        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return str_starts_with($request->url(), 'https://www.datos.gov.co/resource/32sa-8pi3.json?')
                && ($query['$limit'] ?? null) === '1'
                && ($query['$order'] ?? null) === 'vigenciadesde DESC';
        });

        Carbon::setTestNow();
    }
}
