<?php

namespace App\Classes\ChatAIService;

use App\Models\Message;

class AnswerResponse
{
    public function __construct(
        private readonly Message $answerMessage,
        private readonly ChatAIAnswer $chatAIAnswer,
        private readonly ?array $audioPayload = null
    ) {
    }

    public function toArray(): array
    {
        $payload = $this->answerMessage->data ?? [];
        $payload['chat_ai'] = $payload['chat_ai'] ?? $this->chatAIAnswer->toArray();

        return [
            'chat_id' => $this->answerMessage->chat_id,
            'message_id' => $this->answerMessage->id,
            'text' => $this->answerMessage->text,
            'audio_url' => $this->answerMessage->audio ?? ($this->audioPayload['audio_url'] ?? null),
            'source' => $this->answerMessage->source,
            'data' => $payload,
        ];
    }
}
