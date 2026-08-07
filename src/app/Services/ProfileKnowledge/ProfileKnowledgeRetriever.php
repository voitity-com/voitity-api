<?php

namespace App\Services\ProfileKnowledge;

use App\Classes\EmbeddingService\EmbeddingClient;
use App\Models\Profile;
use App\Models\ProfileKnowledgeChunk;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProfileKnowledgeRetriever
{
    public function __construct(
        private readonly EmbeddingClient $embeddings,
        private readonly ProfileKnowledgeQueryIntentAnalyzer $intentAnalyzer,
    ) {}

    public function retrieve(Profile $profile, string $query): ProfileKnowledgeRetrievalResult
    {
        $startedAt = hrtime(true);
        $embedding = $this->embeddings->embed([$query]);
        $vector = $embedding->vectors[0] ?? [];
        $dimensions = (int) config('ai-knowledge.embedding.dimensions', 1536);
        $intent = $this->intentAnalyzer->analyze($query);
        $forcedTypes = $intent->sourceTypes;

        if (count($vector) !== $dimensions) {
            throw new \RuntimeException('Query embedding dimensions do not match the configured knowledge index.');
        }

        $candidates = DB::getDriverName() === 'pgsql'
            ? $this->postgresCandidates($profile, $vector)
            : $this->portableCandidates($profile, $vector);
        $candidates = collect([
            ...$this->lexicalCandidates($profile, $vector, $intent)->all(),
            ...$candidates->all(),
            ...$this->forcedCandidates($profile, $vector, $intent)->all(),
        ])
            ->unique('id')
            ->reject(fn (ProfileKnowledgeChunk $chunk): bool => in_array($chunk->source_type, $intent->excludedSourceTypes, true))
            ->values();
        $queryTerms = $intent->terms;
        $minimumScore = (float) config('ai-knowledge.retrieval.minimum_score', 0.35);
        $topK = (int) config('ai-knowledge.retrieval.top_k', 8);
        $maxTokens = (int) config('ai-knowledge.retrieval.max_context_tokens', 2500);

        $ranked = $candidates
            ->map(function (ProfileKnowledgeChunk $chunk) use ($intent, $queryTerms): array {
                $semantic = max(-1, min(1, (float) $chunk->getAttribute('semantic_score')));
                $keyword = $this->keywordScore($chunk, $queryTerms);
                $lexical = max(0, min(1, (float) ($chunk->getAttribute('lexical_score') ?? 0)));
                $identifier = $this->identifierScore($chunk, $intent->identifiers);
                $forced = $this->isForcedResult($chunk, $intent, max($keyword, $lexical), $identifier);
                $score = max(
                    $semantic,
                    ($semantic * 0.65) + (max($keyword, $lexical) * 0.25) + ($identifier * 0.1),
                    max($keyword, $lexical) * 0.8,
                    $identifier,
                );

                if ($forced) {
                    $score = max($score, 0.55 + (max($keyword, $lexical) * 0.25) + ($identifier * 0.2));
                }

                return compact('chunk', 'semantic', 'keyword', 'lexical', 'identifier', 'forced', 'score');
            })
            ->filter(fn (array $item): bool => $item['forced'] || $item['score'] >= $minimumScore)
            ->sortByDesc(fn (array $item): string => sprintf('%01.6f-%01.6f-%020d', $item['score'], $item['keyword'], $item['chunk']->id))
            ->values();

        $selected = $this->selectWithinBudget($ranked, $forcedTypes, $topK, $maxTokens);
        $contextTokens = (int) $selected->sum(fn (array $item): int => $this->estimatedTokens($item['chunk']->content));
        $items = $selected->map(fn (array $item): array => [
            'chunk_id' => (int) $item['chunk']->id,
            'source_type' => (string) $item['chunk']->source_type,
            'source_id' => $item['chunk']->source_id !== null ? (string) $item['chunk']->source_id : null,
            'content' => (string) $item['chunk']->content,
            'metadata' => (array) ($item['chunk']->metadata ?? []),
            'score' => (float) $item['score'],
            'semantic_score' => (float) $item['semantic'],
            'keyword_score' => (float) $item['keyword'],
            'lexical_score' => (float) $item['lexical'],
            'identifier_score' => (float) $item['identifier'],
            'forced' => (bool) $item['forced'],
        ])->all();
        $latencyMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        Log::info('Profile knowledge retrieval completed.', [
            'profile_id' => $profile->id,
            'chunk_ids' => array_column($items, 'chunk_id'),
            'source_types' => array_values(array_unique(array_column($items, 'source_type'))),
            'intent_source_types' => $intent->sourceTypes,
            'intent_providers' => $intent->providers,
            'scores' => collect($items)->map(fn (array $item): array => [
                'chunk_id' => $item['chunk_id'],
                'score' => round($item['score'], 4),
                'semantic' => round($item['semantic_score'], 4),
                'keyword' => round($item['keyword_score'], 4),
                'lexical' => round($item['lexical_score'], 4),
                'identifier' => round($item['identifier_score'], 4),
                'forced' => $item['forced'],
            ])->all(),
            'query_tokens' => $embedding->inputTokens,
            'context_tokens' => $contextTokens,
            'latency_ms' => $latencyMs,
        ]);

        return new ProfileKnowledgeRetrievalResult(
            items: $items,
            queryTokens: $embedding->inputTokens,
            contextTokens: $contextTokens,
            latencyMs: $latencyMs,
        );
    }

    /**
     * Add candidates selected by exact words, names, references and numbers so
     * semantic nearest-neighbour search is not the only gateway into ranking.
     *
     * @param  array<int, float>  $vector
     */
    private function lexicalCandidates(
        Profile $profile,
        array $vector,
        ProfileKnowledgeQueryIntent $intent,
    ): Collection {
        if ($intent->terms === []) {
            return collect();
        }

        $query = ProfileKnowledgeChunk::query()
            ->where('profile_id', $profile->id)
            ->where('active', true)
            ->where('visibility', 'public')
            ->where('embedding_model', config('ai-knowledge.embedding.model'))
            ->where('embedding_dimensions', config('ai-knowledge.embedding.dimensions'))
            ->whereNotNull('embedding');

        if (DB::getDriverName() === 'pgsql') {
            $literal = $this->vectorLiteral($vector);
            $tsQuery = collect($intent->terms)
                ->take(12)
                ->map(fn (string $term): string => preg_replace('/[^a-z0-9]+/', '', Str::ascii($term)).':*')
                ->filter(fn (string $term): bool => $term !== ':*')
                ->implode(' | ');

            if ($tsQuery === '') {
                return collect();
            }

            return $query
                ->whereRaw("to_tsvector('simple', coalesce(content, '')) @@ to_tsquery('simple', ?)", [$tsQuery])
                ->select('profile_knowledge_chunks.*')
                ->selectRaw('1 - (embedding <=> CAST(? AS vector)) AS semantic_score', [$literal])
                ->selectRaw("ts_rank_cd(to_tsvector('simple', coalesce(content, '')), to_tsquery('simple', ?)) AS lexical_score", [$tsQuery])
                ->orderByDesc('lexical_score')
                ->limit((int) config('ai-knowledge.retrieval.lexical_candidate_limit', 30))
                ->get();
        }

        return $query->get()
            ->each(function (ProfileKnowledgeChunk $chunk) use ($intent, $vector): void {
                $stored = $chunk->getAttribute('embedding');
                $stored = is_string($stored) ? json_decode($stored, true) : $stored;
                $chunk->setAttribute('semantic_score', $this->cosineSimilarity($vector, is_array($stored) ? $stored : []));
                $chunk->setAttribute('lexical_score', $this->keywordScore($chunk, $intent->terms));
            })
            ->filter(fn (ProfileKnowledgeChunk $chunk): bool => (float) $chunk->getAttribute('lexical_score') > 0)
            ->sortByDesc(fn (ProfileKnowledgeChunk $chunk): float => (float) $chunk->getAttribute('lexical_score'))
            ->take((int) config('ai-knowledge.retrieval.lexical_candidate_limit', 30))
            ->values();
    }

    /** @param array<int, float> $vector */
    private function postgresCandidates(Profile $profile, array $vector): Collection
    {
        $literal = $this->vectorLiteral($vector);

        return ProfileKnowledgeChunk::query()
            ->where('profile_id', $profile->id)
            ->where('active', true)
            ->where('visibility', 'public')
            ->where('embedding_model', config('ai-knowledge.embedding.model'))
            ->where('embedding_dimensions', config('ai-knowledge.embedding.dimensions'))
            ->whereNotNull('embedding')
            ->select('profile_knowledge_chunks.*')
            ->selectRaw('1 - (embedding <=> CAST(? AS vector)) AS semantic_score', [$literal])
            ->orderByRaw('embedding <=> CAST(? AS vector)', [$literal])
            ->limit((int) config('ai-knowledge.retrieval.candidate_limit', 40))
            ->get();
    }

    /** @param array<int, float> $vector */
    private function portableCandidates(Profile $profile, array $vector): Collection
    {
        return ProfileKnowledgeChunk::query()
            ->where('profile_id', $profile->id)
            ->where('active', true)
            ->where('visibility', 'public')
            ->where('embedding_model', config('ai-knowledge.embedding.model'))
            ->where('embedding_dimensions', config('ai-knowledge.embedding.dimensions'))
            ->whereNotNull('embedding')
            ->get()
            ->each(function (ProfileKnowledgeChunk $chunk) use ($vector): void {
                $stored = $chunk->getAttribute('embedding');
                $stored = is_string($stored) ? json_decode($stored, true) : $stored;
                $chunk->setAttribute('semantic_score', $this->cosineSimilarity($vector, is_array($stored) ? $stored : []));
            })
            ->sortByDesc(fn (ProfileKnowledgeChunk $chunk): float => (float) $chunk->getAttribute('semantic_score'))
            ->take((int) config('ai-knowledge.retrieval.candidate_limit', 40))
            ->values();
    }

    /**
     * @param  array<int, float>  $vector
     */
    private function forcedCandidates(
        Profile $profile,
        array $vector,
        ProfileKnowledgeQueryIntent $intent,
    ): Collection {
        if ($intent->sourceTypes === []) {
            return collect();
        }

        $query = ProfileKnowledgeChunk::query()
            ->where('profile_id', $profile->id)
            ->where('active', true)
            ->where('visibility', 'public')
            ->whereIn('source_type', $intent->sourceTypes)
            ->where('embedding_model', config('ai-knowledge.embedding.model'))
            ->where('embedding_dimensions', config('ai-knowledge.embedding.dimensions'))
            ->whereNotNull('embedding');

        if (DB::getDriverName() === 'pgsql') {
            $literal = $this->vectorLiteral($vector);

            return $query
                ->select('profile_knowledge_chunks.*')
                ->selectRaw('1 - (embedding <=> CAST(? AS vector)) AS semantic_score', [$literal])
                ->orderByRaw('embedding <=> CAST(? AS vector)', [$literal])
                ->limit(20)
                ->get();
        }

        return $query->limit(20)->get()->each(function (ProfileKnowledgeChunk $chunk) use ($vector): void {
            $stored = $chunk->getAttribute('embedding');
            $stored = is_string($stored) ? json_decode($stored, true) : $stored;
            $chunk->setAttribute('semantic_score', $this->cosineSimilarity($vector, is_array($stored) ? $stored : []));
        });
    }

    private function isForcedResult(
        ProfileKnowledgeChunk $chunk,
        ProfileKnowledgeQueryIntent $intent,
        float $lexicalScore,
        float $identifierScore,
    ): bool {
        if ($chunk->source_type === 'product_guidance' && $intent->productRecommendation) {
            return true;
        }

        if ($chunk->source_type === 'social_link' && $intent->socialLink) {
            return $intent->providers === [] || in_array((string) $chunk->source_id, $intent->providers, true);
        }

        if ($chunk->source_type === 'integration_media' && $intent->media) {
            $provider = $this->normalize((string) data_get($chunk->metadata, 'provider', ''));
            $destination = $this->normalize((string) data_get($chunk->metadata, 'destination_type', ''));
            $providerMatches = $intent->providers === [] || collect($intent->providers)->contains(
                fn (string $requested): bool => in_array($requested, [$provider, $destination], true)
            );

            return $providerMatches && ($lexicalScore > 0 || $intent->explicitMediaShow);
        }

        if ($chunk->source_type === 'product' && $intent->productRecommendation) {
            return $lexicalScore > 0 || $identifierScore > 0 || $intent->identifiers === [];
        }

        return false;
    }

    /** @param array<int, string> $identifiers */
    private function identifierScore(ProfileKnowledgeChunk $chunk, array $identifiers): float
    {
        if ($identifiers === []) {
            return 0;
        }

        $haystack = $this->normalize(implode(' ', [
            $chunk->content,
            $chunk->source_id,
            json_encode($chunk->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));
        $matches = collect($identifiers)->filter(fn (string $identifier): bool => preg_match(
            '/(?<![\pL\pN])'.preg_quote($identifier, '/').'(?![\pL\pN])/u',
            $haystack
        ) === 1)->count();

        return min(1, $matches / count($identifiers));
    }

    /**
     * @param  Collection<int, array{chunk:ProfileKnowledgeChunk,semantic:float,keyword:float,forced:bool,score:float}>  $ranked
     * @param  array<int, string>  $forcedTypes
     * @return Collection<int, array{chunk:ProfileKnowledgeChunk,semantic:float,keyword:float,forced:bool,score:float}>
     */
    private function selectWithinBudget(Collection $ranked, array $forcedTypes, int $topK, int $maxTokens): Collection
    {
        $forcedAllowance = $forcedTypes === [] ? 0 : min(6, max(2, $topK));
        $limit = $topK + $forcedAllowance;
        $selected = collect();
        $tokens = 0;

        foreach ($ranked as $item) {
            if ($selected->count() >= $limit) {
                break;
            }

            $itemTokens = $this->estimatedTokens($item['chunk']->content);

            if ($selected->isNotEmpty() && $tokens + $itemTokens > $maxTokens) {
                continue;
            }

            $selected->push($item);
            $tokens += $itemTokens;
        }

        return $selected;
    }

    /** @param array<int, string> $queryTerms */
    private function keywordScore(ProfileKnowledgeChunk $chunk, array $queryTerms): float
    {
        if ($queryTerms === []) {
            return 0;
        }

        $haystack = $this->normalize(implode(' ', [
            $chunk->content,
            $chunk->source_type,
            $chunk->source_id,
            json_encode($chunk->metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));
        $matches = collect($queryTerms)->filter(fn (string $term): bool => str_contains($haystack, $term))->count();

        return min(1, $matches / max(1, min(5, count($queryTerms))));
    }

    private function estimatedTokens(string $text): int
    {
        return max(1, (int) ceil(mb_strlen($text) / 4));
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(Str::ascii($value));
    }

    /** @param array<int, float|int> $left @param array<int, float|int> $right */
    private function cosineSimilarity(array $left, array $right): float
    {
        if (count($left) !== count($right) || $left === []) {
            return -1;
        }

        $dot = 0.0;
        $leftMagnitude = 0.0;
        $rightMagnitude = 0.0;

        foreach ($left as $index => $value) {
            $leftValue = (float) $value;
            $rightValue = (float) $right[$index];
            $dot += $leftValue * $rightValue;
            $leftMagnitude += $leftValue ** 2;
            $rightMagnitude += $rightValue ** 2;
        }

        if ($leftMagnitude <= 0 || $rightMagnitude <= 0) {
            return -1;
        }

        return $dot / (sqrt($leftMagnitude) * sqrt($rightMagnitude));
    }

    /** @param array<int, float> $vector */
    private function vectorLiteral(array $vector): string
    {
        return '['.implode(',', array_map(fn ($value): string => (string) (float) $value, $vector)).']';
    }
}
