<?php

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Classes\UsdCopRateService\UsdCopRateManager;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class UsdCopRateControllerTest extends TestAPI
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Config::set('payment.exchange_rates.usd_cop.driver', 'datos_gov');
        Config::set('payment.exchange_rates.usd_cop.fallback_driver', 'config');
        Config::set('payment.usd_cop_rate_drivers.config.rate', 4000);
        app(UsdCopRateManager::class)->forgetDrivers();
    }

    public function test_user_can_get_current_usd_cop_rate(): void
    {
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

        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/payments/usd-cop-rate');

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'USD to COP exchange rate retrieved successfully.');
        $response->assertJsonPath('data.pair', 'USD/COP');
        $response->assertJsonPath('data.base_currency', 'USD');
        $response->assertJsonPath('data.quote_currency', 'COP');
        $response->assertJsonPath('data.rate', 3262.58);
        $response->assertJsonPath('data.source', 'datos_gov');
        $response->assertJsonPath('data.unit', 'COP');
        $response->assertJsonPath('data.valid_from', '2026-07-18T00:00:00.000');
        $response->assertJsonPath('data.valid_to', '2026-07-21T00:00:00.000');
        $response->assertJsonPath('data.stale', false);

        $this->assertSame(3262.58, config('payment.usd_cop_rate'));
    }

    public function test_user_must_have_payments_read_ability_to_get_current_usd_cop_rate(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token', ['payments:create'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/payments/usd-cop-rate');

        $response->assertStatus(403);
    }
}
