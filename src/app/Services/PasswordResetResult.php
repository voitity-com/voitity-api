<?php

namespace App\Services;

enum PasswordResetResult: string
{
    case Valid = 'valid';
    case Expired = 'expired';
    case Invalid = 'invalid';
}
