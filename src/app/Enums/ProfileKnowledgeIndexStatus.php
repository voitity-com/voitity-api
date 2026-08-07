<?php

namespace App\Enums;

enum ProfileKnowledgeIndexStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Outdated = 'outdated';
    case Failed = 'failed';
}
