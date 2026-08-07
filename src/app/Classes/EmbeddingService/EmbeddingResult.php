<?php

namespace App\Classes\EmbeddingService;

class EmbeddingResult
{
    /**
     * @param  array<int, array<int, float>>  $vectors
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        public readonly string $source,
        public readonly string $model,
        public readonly array $vectors,
        public readonly int $inputTokens = 0,
        public readonly array $response = [],
    ) {}

    public function isSuccessful(): bool
    {
        return $this->vectors !== [];
    }
}
