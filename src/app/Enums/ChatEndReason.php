<?php

namespace App\Enums;

enum ChatEndReason: string
{
    case Inactivity = 'inactivity';
    case UserStartedNew = 'user_started_new';
    case Expired = 'expired';
    case Administrative = 'administrative';
}
