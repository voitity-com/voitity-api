<?php

namespace App\Enums;

enum ProfileSourceType: string
{
    case Cv = 'cv';
    case Instagram = 'instagram';
    case Manual = 'manual';
    case Website = 'website';
}
