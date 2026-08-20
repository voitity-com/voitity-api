<?php

namespace App\Enums;

enum BusinessConversationStatus: string
{
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Abandoned = 'abandoned';
    case Failed = 'failed';
}
