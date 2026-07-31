<?php

namespace App\Http\Responses\Credits;

use App\Classes\Subscriptions\CreditAmount;
use App\Models\CreditWallet;

class CreditWalletResponse
{
    public function __construct(private readonly CreditWallet $wallet) {}

    /**
     * @return array<string, int|float>
     */
    public function toArray(): array
    {
        return [
            'available' => CreditAmount::unitsToCredits((int) $this->wallet->available_units),
            'reserved' => CreditAmount::unitsToCredits((int) $this->wallet->reserved_units),
            'debt' => CreditAmount::unitsToCredits((int) $this->wallet->debt_units),
            'lifetime_purchased' => CreditAmount::unitsToCredits((int) $this->wallet->lifetime_purchased_units),
            'lifetime_consumed' => CreditAmount::unitsToCredits((int) $this->wallet->lifetime_consumed_units),
            'units_per_credit' => CreditAmount::unitsPerCredit(),
        ];
    }
}
