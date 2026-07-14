<?php

namespace App\Enums;

enum SubscriptionStatus: string
{
    case First = 'first';
    case Renewed = 'renewed';
    case Trialing = 'trialing';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
}
