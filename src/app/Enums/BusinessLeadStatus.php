<?php

namespace App\Enums;

enum BusinessLeadStatus: string
{
    case Created = 'created';
    case Contacted = 'contacted';
    case Sale = 'sale';
    case NoResponse = 'no_response';
}
