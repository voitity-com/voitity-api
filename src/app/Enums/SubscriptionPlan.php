<?php

namespace App\Enums;

enum SubscriptionPlan: string
{
    case Starter = 'starter';
    case StarterAnnual = 'starter_annual';
    case Pro = 'pro';
    case Business = 'business';
}
