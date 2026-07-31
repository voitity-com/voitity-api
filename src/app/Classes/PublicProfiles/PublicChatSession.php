<?php

namespace App\Classes\PublicProfiles;

use App\Models\Chat;
use App\Models\Profile;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class PublicChatSession
{
    public function issue(Profile $profile, Chat $chat): string
    {
        return Crypt::encryptString((string) json_encode([
            'version' => 1,
            'profile_id' => $profile->id,
            'chat_id' => $chat->id,
            'expires_at' => now()
                ->addMinutes($this->lifetimeMinutes())
                ->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    public function isValid(?string $token, Profile $profile, int $chatId): bool
    {
        if (! filled($token)) {
            return false;
        }

        try {
            $payload = json_decode(
                Crypt::decryptString($token),
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (DecryptException|\JsonException) {
            return false;
        }

        return is_array($payload)
            && (int) ($payload['version'] ?? 0) === 1
            && (int) ($payload['profile_id'] ?? 0) === (int) $profile->id
            && (int) ($payload['chat_id'] ?? 0) === $chatId
            && (int) ($payload['expires_at'] ?? 0) >= now()->timestamp;
    }

    private function lifetimeMinutes(): int
    {
        return max(
            1,
            (int) config('public-profiles.chat_session_lifetime_minutes', 1440),
        );
    }
}
