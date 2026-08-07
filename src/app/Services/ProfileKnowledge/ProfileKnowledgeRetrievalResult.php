<?php

namespace App\Services\ProfileKnowledge;

class ProfileKnowledgeRetrievalResult
{
    /**
     * @param  array<int, array{chunk_id:int,source_type:string,source_id:?string,content:string,metadata:array<string,mixed>,score:float,semantic_score:float,keyword_score:float,lexical_score:float,identifier_score:float,forced:bool}>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $queryTokens,
        public readonly int $contextTokens,
        public readonly int $latencyMs,
    ) {}

    /** @return array<int, int> */
    public function chunkIds(): array
    {
        return array_values(array_map(fn (array $item): int => $item['chunk_id'], $this->items));
    }

    /** @return array<int, string> */
    public function sourceIds(string $sourceType): array
    {
        return collect($this->items)
            ->where('source_type', $sourceType)
            ->pluck('source_id')
            ->filter(fn ($id): bool => $id !== null && $id !== '')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    public function hasSourceType(string $sourceType): bool
    {
        return collect($this->items)->contains(
            fn (array $item): bool => $item['source_type'] === $sourceType
        );
    }
}
