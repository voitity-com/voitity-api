<?php

namespace App\Enums;

enum ProfileStatus: string
{
    case Draft = 'draft';
    case Completed = 'ready';
    case Published = 'published';
    case Hidden = 'hidden';
}
