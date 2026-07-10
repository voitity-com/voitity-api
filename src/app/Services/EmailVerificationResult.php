<?php

namespace App\Services;

enum EmailVerificationResult: string
{
    case Verified = 'verified';
    case AlreadyVerified = 'already_verified';
    case Expired = 'expired';
    case Invalid = 'invalid';
}
