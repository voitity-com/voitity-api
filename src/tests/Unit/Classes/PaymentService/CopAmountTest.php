<?php

namespace Tests\Unit\Classes\PaymentService;

use App\Classes\PaymentService\CopAmount;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CopAmountTest extends TestCase
{
    #[Test]
    #[DataProvider('amounts')]
    public function it_rounds_to_whole_pesos_before_converting_to_cents(
        float $amountUsd,
        float $exchangeRate,
        int $expectedPesos,
    ): void {
        $amount = CopAmount::fromUsd($amountUsd, $exchangeRate);

        $this->assertSame($expectedPesos, $amount->pesos);
        $this->assertSame($expectedPesos * 100, $amount->inCents());
        $this->assertSame(0, $amount->inCents() % 100);
    }

    /**
     * @return array<string, array{float,float,int}>
     */
    public static function amounts(): array
    {
        return [
            'credit pack rounds down' => [10, 3132.42, 31324],
            'plan amount rounds up' => [12.99, 3132.46, 40691],
            'zero remains zero' => [0, 3132.42, 0],
        ];
    }

    #[Test]
    #[DataProvider('invalidInputs')]
    public function it_rejects_invalid_inputs(float $amountUsd, float $exchangeRate): void
    {
        $this->expectException(InvalidArgumentException::class);

        CopAmount::fromUsd($amountUsd, $exchangeRate);
    }

    /**
     * @return array<string, array{float,float}>
     */
    public static function invalidInputs(): array
    {
        return [
            'negative amount' => [-0.01, 3132.42],
            'zero rate' => [10, 0],
            'negative rate' => [10, -1],
            'infinite amount' => [INF, 3132.42],
            'infinite rate' => [10, INF],
        ];
    }
}
