<?php

namespace App\Enums;

enum PaymentOrderStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
    case Voided = 'voided';
    case Error = 'error';
    case Expired = 'expired';
}
