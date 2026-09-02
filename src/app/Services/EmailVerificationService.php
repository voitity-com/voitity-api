<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EmailVerificationService
{
    public function createVerificationUrl(User $user): string
    {
        $token = Str::random(64);
        $now = Carbon::now();

        $user->forceFill([
            'email_verification_token' => hash('sha256', $token),
            'email_verification_sent_at' => $now,
            'email_verification_expires_at' => $now->copy()->addMinutes((int) config('email-verification.expires_in_minutes')),
        ])->save();

        $relativeUrl = route('auth.verify-email', [
            'user' => $user->id,
            'token' => $token,
            'redirect' => 1,
        ], false);

        return rtrim((string) config('app.url'), '/').'/'.ltrim($relativeUrl, '/');
    }

    public function verify(User $user, ?string $token): EmailVerificationResult
    {
        if ($user->email_verified_at) {
            return EmailVerificationResult::AlreadyVerified;
        }

        if (! $token || ! $user->email_verification_token) {
            return EmailVerificationResult::Invalid;
        }

        if ($user->email_verification_expires_at?->isPast()) {
            return EmailVerificationResult::Expired;
        }

        if (! hash_equals($user->email_verification_token, hash('sha256', $token))) {
            return EmailVerificationResult::Invalid;
        }

        $user->forceFill([
            'email_verified_at' => now(),
            'email_verification_token' => null,
            'email_verification_sent_at' => null,
            'email_verification_expires_at' => null,
        ])->save();

        return EmailVerificationResult::Verified;
    }
}
