<?php

namespace App\Classes\BusinessFlowAI;

class BusinessFlowAIResult
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly array $data,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly string $provider = 'local',
        public readonly string $model = 'heuristic-v1',
    ) {}
}
