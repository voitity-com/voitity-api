<?php

namespace App\Exceptions\Subscriptions;

use RuntimeException;

class SubscriptionEntitlementException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        string $message,
        private readonly array $errors = [],
        private readonly int $statusCode = 402
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function errorCode(): string
    {
        if (isset($this->errors['incoming_audio_messages']) || isset($this->errors['incoming_audio_seconds'])) {
            return 'AUDIO_MESSAGE_LIMIT_REACHED';
        }

        if (isset($this->errors['chat_messages'])) {
            return 'CHAT_MESSAGE_LIMIT_REACHED';
        }

        if (isset($this->errors['tts_characters'])) {
            return 'TTS_CHARACTER_LIMIT_REACHED';
        }

        if (isset($this->errors['subscription'])) {
            return 'SUBSCRIPTION_INACTIVE';
        }

        return 'SUBSCRIPTION_LIMIT_REACHED';
    }
}
