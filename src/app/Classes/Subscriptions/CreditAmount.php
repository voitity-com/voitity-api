<?php

namespace App\Classes\Subscriptions;

class CreditAmount
{
    public static function unitsPerCredit(): int
    {
        return max(1, (int) config('subscriptions.credit_store.units_per_credit', 1000));
    }

    public static function creditsToUnits(int|float $credits): int
    {
        return max(0, (int) round($credits * self::unitsPerCredit()));
    }

    public static function unitsToCredits(int $units): float
    {
        return round($units / self::unitsPerCredit(), 3);
    }
}
