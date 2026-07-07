<?php

namespace App\Exceptions\ChatAIService;

use App\Classes\ChatAIService\ChatAIAnswer;
use RuntimeException;

class ChatAIAnswerGenerationFailed extends RuntimeException
{
    public function __construct(private readonly ChatAIAnswer $chatAIAnswer)
    {
        parent::__construct('Message answer generation failed.');
    }

    public function chatAIAnswer(): ChatAIAnswer
    {
        return $this->chatAIAnswer;
    }

    public function context(): array
    {
        return $this->chatAIAnswer->toArray();
    }
}
