<?php

namespace App\Classes\BusinessInstructionAI;

class BusinessInstructionResult
{
    /** @param array<int, int> $sourceChunkIds */
    public function __construct(
        public readonly string $message,
        public readonly array $sourceChunkIds,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}

    public function successful(): bool
    {
        return trim($this->message) !== '';
    }
}
