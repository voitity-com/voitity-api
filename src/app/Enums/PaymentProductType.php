<?php

namespace App\Enums;

enum PaymentProductType: string
{
    case Subscription = 'subscription';
    case CreditPack = 'credit_pack';
}
