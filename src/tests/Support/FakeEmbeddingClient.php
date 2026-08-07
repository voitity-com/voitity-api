<?php

namespace Tests\Support;

use App\Classes\EmbeddingService\EmbeddingClient;
use App\Classes\EmbeddingService\EmbeddingResult;

class FakeEmbeddingClient implements EmbeddingClient
{
    /** @var array<int, array<int, string>> */
    public array $calls = [];

    /** @param callable(string, int): array<int, float> $resolver */
    public function __construct(private readonly mixed $resolver) {}

    public function embed(array $inputs): EmbeddingResult
    {
        $this->calls[] = $inputs;
        $resolver = $this->resolver;
        $vectors = collect(array_values($inputs))
            ->map(fn (string $input, int $index): array => $resolver($input, $index))
            ->all();

        return new EmbeddingResult(
            source: 'fake',
            model: (string) config('ai-knowledge.embedding.model'),
            vectors: $vectors,
            inputTokens: collect($inputs)->sum(fn (string $input): int => max(1, str_word_count($input))),
            response: ['fake' => true],
        );
    }
}
