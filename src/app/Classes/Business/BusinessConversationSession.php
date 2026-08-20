<?php

namespace App\Classes\Business;

use App\Models\BusinessApiClient;
use App\Models\BusinessConversation;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class BusinessConversationSession
{
    public function issue(BusinessConversation $conversation, BusinessApiClient $client, string $origin): string
    {
        return Crypt::encryptString(json_encode([
            'version' => 1,
            'conversation_id' => $conversation->id,
            'business_id' => $conversation->business_id,
            'client_id' => $client->id,
            'origin' => $origin,
            'expires_at' => now()->addDay()->timestamp,
        ], JSON_THROW_ON_ERROR));
    }

    public function isValid(?string $token, BusinessConversation $conversation, BusinessApiClient $client, string $origin): bool
    {
        if (! filled($token)) {
            return false;
        }
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return false;
        }

        return is_array($payload)
            && (int) ($payload['version'] ?? 0) === 1
            && (int) ($payload['conversation_id'] ?? 0) === $conversation->id
            && (int) ($payload['business_id'] ?? 0) === $conversation->business_id
            && (int) ($payload['client_id'] ?? 0) === $client->id
            && hash_equals((string) ($payload['origin'] ?? ''), $origin)
            && (int) ($payload['expires_at'] ?? 0) >= now()->timestamp;
    }
}
