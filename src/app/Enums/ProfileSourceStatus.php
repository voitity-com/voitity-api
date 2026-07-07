<?php

namespace App\Enums;

enum ProfileSourceStatus: string
{
    case Uploaded = 'uploaded';
    case Parsed = 'parsed';
    case NeedsReview = 'needs_review';
    case Approved = 'approved';
    case Indexed = 'indexed';
    case Failed = 'failed';
}
