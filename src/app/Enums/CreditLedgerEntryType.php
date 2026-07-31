<?php

namespace App\Enums;

enum CreditLedgerEntryType: string
{
    case Purchase = 'purchase';
    case Reserve = 'reserve';
    case Consume = 'consume';
    case Release = 'release';
    case Reversal = 'reversal';
    case AdminAdjustment = 'admin_adjustment';
}
