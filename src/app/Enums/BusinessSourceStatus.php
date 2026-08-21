<?php

namespace App\Enums;

enum BusinessSourceStatus: string
{
    case Processing = 'processing';
    case Indexed = 'indexed';
    case Failed = 'failed';
}
