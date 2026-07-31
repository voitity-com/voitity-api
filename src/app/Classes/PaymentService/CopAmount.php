<?php

namespace App\Classes\PaymentService;

use InvalidArgumentException;

final readonly class CopAmount
{
    private function __construct(
        public int $pesos,
    ) {}

    public static function fromUsd(float $amountUsd, float $exchangeRate): self
    {
        if (! is_finite($amountUsd) || $amountUsd < 0) {
            throw new InvalidArgumentException('The USD amount must be a finite non-negative number.');
        }

        if (! is_finite($exchangeRate) || $exchangeRate <= 0) {
            throw new InvalidArgumentException('The USD to COP exchange rate must be a finite positive number.');
        }

        return new self((int) round($amountUsd * $exchangeRate));
    }

    public function inCents(): int
    {
        return $this->pesos * 100;
    }
}
