<?php

namespace App\Enums;

enum ChatAnalysisStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case NeedsReview = 'needs_review';
    case Failed = 'failed';
}
