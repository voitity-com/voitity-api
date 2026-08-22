<?php

namespace App\Classes\BusinessProblemAI;

class BusinessProblemResult
{
    /** @param array<int, int> $evidenceMessageIds */
    public function __construct(
        public readonly string $summary,
        public readonly array $evidenceMessageIds,
        public readonly float $confidence,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
    ) {}

    public function successful(): bool
    {
        return trim($this->summary) !== '';
    }
}
