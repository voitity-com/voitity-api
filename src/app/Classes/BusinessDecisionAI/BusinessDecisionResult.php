<?php

namespace App\Classes\BusinessDecisionAI;

class BusinessDecisionResult
{
    /** @param array<int, int> $sourceChunkIds */
    public function __construct(
        public readonly bool $answer,
        public readonly float $confidence,
        public readonly string $reason,
        public readonly array $sourceChunkIds,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}
}
