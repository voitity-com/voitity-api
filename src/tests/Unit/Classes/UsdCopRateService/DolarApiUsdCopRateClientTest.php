<?php

namespace Tests\Unit\Classes\UsdCopRateService;

use App\Classes\UsdCopRateService\DolarApi\DolarApiUsdCopRateClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DolarApiUsdCopRateClientTest extends TestCase
{
    #[Test]
    public function it_parses_latest_trm_from_dolar_api(): void
    {
        Carbon::setTestNow('2026-07-21 10:00:00');

        Http::fake([
            'https://co.dolarapi.com/v1/trm' => Http::response([
                'valor' => 3262.58,
                'nombre' => 'TRM',
                'unidad' => 'COP',
                'fechaActualizacion' => '2026-07-21T00:00:00.000Z',
            ]),
        ]);

        $rate = (new DolarApiUsdCopRateClient)->latest();

        $this->assertSame(3262.58, $rate->value);
        $this->assertSame('dolar_api', $rate->source);
        $this->assertSame('COP', $rate->unit);
        $this->assertSame('2026-07-21T00:00:00.000Z', $rate->validFrom);
        $this->assertSame('2026-07-21T10:00:00.000000Z', $rate->fetchedAt);

        Http::assertSentCount(1);

        Carbon::setTestNow();
    }
}
