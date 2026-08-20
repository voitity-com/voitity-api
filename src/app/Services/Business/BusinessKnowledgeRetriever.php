<?php

namespace App\Services\Business;

use App\Models\Business;

class BusinessKnowledgeRetriever
{
    /** @return array{content: string, chunk_ids: array<int, int>, tokens: int} */
    public function retrieve(Business $business, string $query, int $limit = 4): array
    {
        $terms = collect(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [])
            ->filter(fn (string $term): bool => mb_strlen($term) >= 4)
            ->unique()
            ->take(12);

        if ($terms->isEmpty()) {
            return ['content' => '', 'chunk_ids' => [], 'tokens' => 0];
        }

        $chunks = $business->sources()->where('status', 'indexed')->with('chunks')->get()
            ->flatMap->chunks
            ->map(function ($chunk) use ($terms): array {
                $content = mb_strtolower($chunk->content);
                $score = $terms->sum(fn (string $term): int => substr_count($content, $term));

                return ['chunk' => $chunk, 'score' => $score];
            })
            ->filter(fn (array $row): bool => $row['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('chunk');

        return [
            'content' => $chunks->pluck('content')->implode("\n\n"),
            'chunk_ids' => $chunks->pluck('id')->all(),
            'tokens' => (int) $chunks->sum('token_count'),
        ];
    }
}
