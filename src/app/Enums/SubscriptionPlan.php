<?php

namespace App\Enums;

enum SubscriptionPlan: string
{
    case Free = 'free';
    case Starter = 'starter';
    case StarterAnnual = 'starter_annual';
    case Admin = 'admin';
    case Pro = 'pro';
    case Business = 'business';
}
