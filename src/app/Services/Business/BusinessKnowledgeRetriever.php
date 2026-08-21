<?php

namespace App\Services\Business;

use App\Classes\EmbeddingService\EmbeddingClient;
use App\Models\Business;
use App\Models\BusinessKnowledgeChunk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class BusinessKnowledgeRetriever
{
    public function __construct(private readonly EmbeddingClient $embeddings) {}

    /**
     * @return array{
     *   content:string,
     *   items:array<int,array{chunk_id:int,source_id:int,source_name:string,content:string,score:float,semantic_score:float,lexical_score:float}>,
     *   chunk_ids:array<int,int>,query_tokens:int,context_tokens:int,latency_ms:int,provider:?string,model:?string
     * }
     */
    public function retrieve(Business $business, string $query, ?int $limit = null): array
    {
        $startedAt = hrtime(true);
        $query = trim($query);
        if ($query === '' || ! $this->availableChunks($business)->exists()) {
            return $this->emptyResult($startedAt);
        }

        $embedding = $this->embeddings->embed([$query]);
        $vector = $embedding->vectors[0] ?? [];
        $dimensions = (int) config('ai-knowledge.embedding.dimensions', 1536);
        if (count($vector) !== $dimensions) {
            throw new RuntimeException('Business query embedding dimensions do not match the configured knowledge index.');
        }

        $terms = $this->terms($query);
        $semantic = DB::getDriverName() === 'pgsql'
            ? $this->postgresCandidates($business, $vector)
            : $this->portableCandidates($business, $vector);
        $lexical = $this->lexicalCandidates($business, $terms, $vector);
        $minimumScore = (float) config('business-ai.knowledge.minimum_score', 0.32);
        $topK = $limit ?? (int) config('business-ai.knowledge.top_k', 6);
        $maxTokens = (int) config('business-ai.knowledge.max_context_tokens', 2600);

        $ranked = collect([...$semantic->all(), ...$lexical->all()])
            ->unique('id')
            ->map(function (BusinessKnowledgeChunk $chunk) use ($terms): array {
                $semanticScore = max(-1, min(1, (float) ($chunk->getAttribute('semantic_score') ?? -1)));
                $lexicalScore = max(
                    (float) ($chunk->getAttribute('lexical_score') ?? 0),
                    $this->keywordScore($chunk->content, $terms),
                );
                $score = max(
                    $semanticScore,
                    (max(0, $semanticScore) * 0.72) + ($lexicalScore * 0.28),
                    $lexicalScore * 0.85,
                );

                return compact('chunk', 'semanticScore', 'lexicalScore', 'score');
            })
            ->filter(fn (array $item): bool => $item['score'] >= $minimumScore)
            ->sortByDesc(fn (array $item): string => sprintf('%01.6f-%020d', $item['score'], $item['chunk']->id))
            ->values();

        $selected = collect();
        $contextTokens = 0;
        foreach ($ranked as $item) {
            if ($selected->count() >= $topK) {
                break;
            }
            $tokens = (int) $item['chunk']->token_count;
            if ($selected->isNotEmpty() && $contextTokens + $tokens > $maxTokens) {
                continue;
            }
            $selected->push($item);
            $contextTokens += $tokens;
        }

        $items = $selected->map(fn (array $item): array => [
            'chunk_id' => (int) $item['chunk']->id,
            'source_id' => (int) $item['chunk']->business_source_id,
            'source_name' => (string) $item['chunk']->source->name,
            'content' => (string) $item['chunk']->content,
            'score' => (float) $item['score'],
            'semantic_score' => (float) $item['semanticScore'],
            'lexical_score' => (float) $item['lexicalScore'],
        ])->all();
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        Log::info('Business knowledge retrieval completed.', [
            'business_id' => $business->id,
            'query_hash' => hash('sha256', $query),
            'chunk_ids' => array_column($items, 'chunk_id'),
            'source_ids' => array_values(array_unique(array_column($items, 'source_id'))),
            'scores' => collect($items)->map(fn (array $item): array => [
                'chunk_id' => $item['chunk_id'],
                'score' => round($item['score'], 4),
                'semantic' => round($item['semantic_score'], 4),
                'lexical' => round($item['lexical_score'], 4),
            ])->all(),
            'query_tokens' => $embedding->inputTokens,
            'context_tokens' => $contextTokens,
            'latency_ms' => $latencyMs,
            'provider' => $embedding->source,
            'model' => $embedding->model,
        ]);

        return [
            'content' => collect($items)->map(
                fn (array $item): string => "[Fuente {$item['source_name']} · fragmento {$item['chunk_id']}]\n{$item['content']}"
            )->implode("\n\n"),
            'items' => $items,
            'chunk_ids' => array_column($items, 'chunk_id'),
            'query_tokens' => $embedding->inputTokens,
            'context_tokens' => $contextTokens,
            'latency_ms' => $latencyMs,
            'provider' => $embedding->source,
            'model' => $embedding->model,
        ];
    }

    /** @return Builder<BusinessKnowledgeChunk> */
    private function availableChunks(Business $business): Builder
    {
        return BusinessKnowledgeChunk::query()
            ->where('business_knowledge_chunks.business_id', $business->id)
            ->where('active', true)
            ->where('embedding_model', config('ai-knowledge.embedding.model'))
            ->where('embedding_dimensions', config('ai-knowledge.embedding.dimensions'))
            ->whereNotNull('embedding')
            ->whereHas('source', fn (Builder $query) => $query->where('status', 'indexed'))
            ->with('source:id,name');
    }

    /** @param array<int, float> $vector @return Collection<int, BusinessKnowledgeChunk> */
    private function postgresCandidates(Business $business, array $vector): Collection
    {
        $literal = $this->vectorLiteral($vector);

        return $this->availableChunks($business)
            ->select('business_knowledge_chunks.*')
            ->selectRaw('1 - (embedding <=> CAST(? AS vector)) AS semantic_score', [$literal])
            ->orderByRaw('embedding <=> CAST(? AS vector)', [$literal])
            ->limit((int) config('business-ai.knowledge.candidate_limit', 30))
            ->get();
    }

    /** @param array<int, float> $vector @return Collection<int, BusinessKnowledgeChunk> */
    private function portableCandidates(Business $business, array $vector): Collection
    {
        return $this->availableChunks($business)->get()
            ->each(function (BusinessKnowledgeChunk $chunk) use ($vector): void {
                $stored = $chunk->getAttribute('embedding');
                $stored = is_string($stored) ? json_decode($stored, true) : $stored;
                $chunk->setAttribute('semantic_score', $this->cosineSimilarity($vector, is_array($stored) ? $stored : []));
            })
            ->sortByDesc(fn (BusinessKnowledgeChunk $chunk): float => (float) $chunk->getAttribute('semantic_score'))
            ->take((int) config('business-ai.knowledge.candidate_limit', 30))
            ->values();
    }

    /** @param array<int, string> $terms @param array<int, float> $vector @return Collection<int, BusinessKnowledgeChunk> */
    private function lexicalCandidates(Business $business, array $terms, array $vector): Collection
    {
        if ($terms === []) {
            return collect();
        }

        return $this->availableChunks($business)->get()
            ->each(function (BusinessKnowledgeChunk $chunk) use ($terms, $vector): void {
                $stored = $chunk->getAttribute('embedding');
                $stored = is_string($stored) ? json_decode($stored, true) : $stored;
                $chunk->setAttribute('semantic_score', $this->cosineSimilarity($vector, is_array($stored) ? $stored : []));
                $chunk->setAttribute('lexical_score', $this->keywordScore($chunk->content, $terms));
            })
            ->filter(fn (BusinessKnowledgeChunk $chunk): bool => (float) $chunk->getAttribute('lexical_score') > 0)
            ->sortByDesc(fn (BusinessKnowledgeChunk $chunk): float => (float) $chunk->getAttribute('lexical_score'))
            ->take((int) config('business-ai.knowledge.candidate_limit', 30))
            ->values();
    }

    /** @return array<int, string> */
    private function terms(string $query): array
    {
        return collect(preg_split('/[^\p{L}\p{N}]+/u', Str::lower(Str::ascii($query))) ?: [])
            ->filter(fn (string $term): bool => mb_strlen($term) >= 4)
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }

    /** @param array<int, string> $terms */
    private function keywordScore(string $content, array $terms): float
    {
        if ($terms === []) {
            return 0;
        }
        $content = Str::lower(Str::ascii($content));
        $matches = collect($terms)->filter(fn (string $term): bool => str_contains($content, $term))->count();

        return min(1, $matches / max(1, count($terms)));
    }

    /** @param array<int, float> $left @param array<int, float> $right */
    private function cosineSimilarity(array $left, array $right): float
    {
        if ($left === [] || count($left) !== count($right)) {
            return -1;
        }
        $dot = $leftNorm = $rightNorm = 0.0;
        foreach ($left as $index => $value) {
            $rightValue = (float) $right[$index];
            $dot += (float) $value * $rightValue;
            $leftNorm += (float) $value ** 2;
            $rightNorm += $rightValue ** 2;
        }
        if ($leftNorm <= 0 || $rightNorm <= 0) {
            return -1;
        }

        return $dot / (sqrt($leftNorm) * sqrt($rightNorm));
    }

    /** @param array<int, float> $vector */
    private function vectorLiteral(array $vector): string
    {
        return '['.implode(',', array_map(fn ($value): string => (string) (float) $value, $vector)).']';
    }

    /** @return array{content:string,items:array,chunk_ids:array,query_tokens:int,context_tokens:int,latency_ms:int,provider:null,model:null} */
    private function emptyResult(int $startedAt): array
    {
        return [
            'content' => '',
            'items' => [],
            'chunk_ids' => [],
            'query_tokens' => 0,
            'context_tokens' => 0,
            'latency_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            'provider' => null,
            'model' => null,
        ];
    }
}
