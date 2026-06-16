<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case First = 'first';
    case Renewed = 'renewed';
    case Expired = 'expired';
}
