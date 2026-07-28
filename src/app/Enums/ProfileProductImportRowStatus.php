<?php

namespace App\Enums;

enum ProfileProductImportRowStatus: string
{
    case DuplicateExisting = 'duplicate_existing';
    case DuplicateFile = 'duplicate_file';
    case Invalid = 'invalid';
    case Valid = 'valid';
}
