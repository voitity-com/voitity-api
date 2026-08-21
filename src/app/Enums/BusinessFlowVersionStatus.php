<?php

namespace App\Enums;

enum BusinessFlowVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
