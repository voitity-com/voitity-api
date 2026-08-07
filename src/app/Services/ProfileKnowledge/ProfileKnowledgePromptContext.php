<?php

namespace App\Services\ProfileKnowledge;

class ProfileKnowledgePromptContext
{
    public function __construct(
        public readonly ProfileKnowledgeRetrievalResult $retrieval,
        public readonly int $indexId,
    ) {}

    /** @return array<string, mixed> */
    public function metadata(): array
    {
        $metadata = [
            'mode' => 'rag',
            'index_id' => $this->indexId,
            'retrieved_chunk_ids' => $this->retrieval->chunkIds(),
            'retrieved_sources' => collect($this->retrieval->items)
                ->map(fn (array $item): array => [
                    'chunk_id' => $item['chunk_id'],
                    'source_type' => $item['source_type'],
                    'source_id' => $item['source_id'],
                    'score' => round($item['score'], 4),
                    'semantic_score' => round($item['semantic_score'], 4),
                    'keyword_score' => round($item['keyword_score'], 4),
                    'lexical_score' => round($item['lexical_score'], 4),
                    'identifier_score' => round($item['identifier_score'], 4),
                    'forced' => $item['forced'],
                ])
                ->values()
                ->all(),
            'query_tokens' => $this->retrieval->queryTokens,
            'context_tokens' => $this->retrieval->contextTokens,
            'retrieval_latency_ms' => $this->retrieval->latencyMs,
        ];

        return $metadata;
    }
}
