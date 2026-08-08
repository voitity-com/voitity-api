<?php

namespace App\Enums;

enum ProfileSourceStatus: string
{
    case Uploaded = 'uploaded';
    case Parsed = 'parsed';
    case NeedsReview = 'needs_review';
    case Approved = 'approved';
    case PendingSync = 'pending_sync';
    case Syncing = 'syncing';
    case Indexing = 'indexing';
    case Indexed = 'indexed';
    case Duplicate = 'duplicate';
    case Failed = 'failed';
}
