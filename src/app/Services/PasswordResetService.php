<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PasswordResetService
{
    public function createResetUrl(User $user): string
    {
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            [
                'token' => hash('sha256', $token),
                'created_at' => now(),
            ],
        );

        return route('auth.password.reset.form', [
            'email' => $user->email,
            'locale' => $user->locale ?: 'en',
            'token' => $token,
        ], true);
    }

    public function verify(User $user, ?string $token): PasswordResetResult
    {
        if (! $token) {
            return PasswordResetResult::Invalid;
        }

        $record = DB::table('password_reset_tokens')->where('email', $user->email)->first();

        if (! $record || ! isset($record->token)) {
            return PasswordResetResult::Invalid;
        }

        if (! isset($record->created_at) || Carbon::parse($record->created_at)->addMinutes($this->expiresInMinutes())->isPast()) {
            return PasswordResetResult::Expired;
        }

        if (! hash_equals((string) $record->token, hash('sha256', $token))) {
            return PasswordResetResult::Invalid;
        }

        return PasswordResetResult::Valid;
    }

    public function consume(User $user): void
    {
        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
    }

    private function expiresInMinutes(): int
    {
        return (int) config('password-reset.expires_in_minutes');
    }
}
