<?php

namespace App\Services\ProfileKnowledge;

use App\Classes\EmbeddingService\EmbeddingClient;
use App\Enums\ProfileKnowledgeIndexStatus;
use App\Enums\ProfileSourceStatus;
use App\Models\Profile;
use App\Models\ProfileFact;
use App\Models\ProfileKnowledgeChunk;
use App\Models\ProfileKnowledgeIndex;
use App\Models\ProfileSource;
use App\Models\ProfileSourceItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ProfileKnowledgeIndexer
{
    public function __construct(
        private readonly EmbeddingClient $embeddings,
        private readonly ProfileKnowledgeDocumentBuilder $documents,
        private readonly ProfileKnowledgeSourceDeduplicator $sourceDeduplicator,
    ) {}

    /**
     * @return array{profile_id:int,status:string,total_chunks:int,active_chunks:int,embedded_chunks:int,embedding_tokens:int}
     */
    public function index(Profile $profile, bool $force = false): array
    {
        $model = (string) config('ai-knowledge.embedding.model', 'text-embedding-3-small');
        $dimensions = (int) config('ai-knowledge.embedding.dimensions', 1536);
        $index = ProfileKnowledgeIndex::query()->updateOrCreate(
            ['profile_id' => $profile->id],
            [
                'status' => ProfileKnowledgeIndexStatus::Processing,
                'index_version' => config('ai-knowledge.indexing.version'),
                'embedding_model' => $model,
                'embedding_dimensions' => $dimensions,
                'last_error' => null,
                'started_at' => now(),
            ],
        );

        Log::info('Profile knowledge indexing started.', [
            'profile_id' => $profile->id,
            'model' => $model,
            'dimensions' => $dimensions,
            'force' => $force,
        ]);

        try {
            $duplicates = $this->sourceDeduplicator->synchronize($profile);
            $profile->unsetRelation('sources');
            $documents = collect($this->documents->build($profile))->keyBy(fn (ProfileKnowledgeDocument $document): string => $document->key);
            $existing = ProfileKnowledgeChunk::query()
                ->where('profile_id', $profile->id)
                ->get()
                ->keyBy('chunk_key');
            $toEmbed = $documents->filter(function (ProfileKnowledgeDocument $document) use ($dimensions, $existing, $force, $model): bool {
                if ($force) {
                    return true;
                }

                $chunk = $existing->get($document->key);

                return ! $chunk instanceof ProfileKnowledgeChunk
                    || $chunk->content_hash !== $document->contentHash()
                    || $chunk->embedding_model !== $model
                    || (int) $chunk->embedding_dimensions !== $dimensions
                    || $chunk->getAttribute('embedding') === null;
            })->values();

            $embeddingTokens = 0;
            $embeddedChunks = 0;
            $vectorsByKey = [];
            $batchSize = (int) config('ai-knowledge.indexing.batch_size', 50);

            foreach ($toEmbed->chunk($batchSize) as $batch) {
                $result = $this->embeddings->embed($batch->map(fn (ProfileKnowledgeDocument $document): string => $document->content)->all());
                $embeddingTokens += $result->inputTokens;

                foreach ($batch->values() as $position => $document) {
                    $vectorsByKey[$document->key] = $result->vectors[$position];
                    $embeddedChunks++;
                }
            }

            $indexedSourceIds = $documents
                ->map(function (ProfileKnowledgeDocument $document): ?int {
                    if ($document->sourceType === 'profile_source') {
                        return (int) $document->sourceId;
                    }

                    if (in_array($document->sourceType, ['profile_source_item', 'profile_fact'], true)) {
                        return (int) ($document->metadata['profile_source_id'] ?? 0);
                    }

                    return null;
                })
                ->filter(fn (?int $id): bool => $id !== null && $id > 0)
                ->unique()
                ->values()
                ->all();
            $indexedSourceItemIds = $documents
                ->filter(fn (ProfileKnowledgeDocument $document): bool => $document->sourceType === 'profile_source_item')
                ->map(fn (ProfileKnowledgeDocument $document): int => (int) $document->sourceId)
                ->filter()
                ->unique()
                ->values()
                ->all();
            $indexedFactIds = $documents
                ->filter(fn (ProfileKnowledgeDocument $document): bool => $document->sourceType === 'profile_fact')
                ->map(fn (ProfileKnowledgeDocument $document): int => (int) $document->sourceId)
                ->filter()
                ->unique()
                ->values()
                ->all();

            DB::transaction(function () use ($dimensions, $documents, $indexedFactIds, $indexedSourceIds, $indexedSourceItemIds, $model, $profile, $vectorsByKey): void {
                foreach ($documents as $document) {
                    $chunk = ProfileKnowledgeChunk::query()->updateOrCreate(
                        ['profile_id' => $profile->id, 'chunk_key' => $document->key],
                        [
                            'source_type' => $document->sourceType,
                            'source_id' => $document->sourceId,
                            'locale' => $document->locale,
                            'content' => $document->content,
                            'content_hash' => $document->contentHash(),
                            'metadata' => $document->metadata,
                            'visibility' => $document->visibility,
                            'active' => $document->active,
                            'embedding_model' => $model,
                            'embedding_dimensions' => $dimensions,
                            ...(isset($vectorsByKey[$document->key]) ? ['embedded_at' => now()] : []),
                        ],
                    );

                    if (isset($vectorsByKey[$document->key])) {
                        $this->storeVector($chunk, $vectorsByKey[$document->key], $dimensions);
                    }
                }

                ProfileKnowledgeChunk::query()
                    ->where('profile_id', $profile->id)
                    ->whereNotIn('chunk_key', $documents->keys()->all())
                    ->delete();

                ProfileSource::query()
                    ->where('profile_id', $profile->id)
                    ->whereNotNull('approved_at')
                    ->update([
                        'status' => ProfileSourceStatus::Approved->value,
                        'indexed_at' => null,
                    ]);
                ProfileSource::query()
                    ->where('profile_id', $profile->id)
                    ->whereIn('id', $indexedSourceIds)
                    ->update([
                        'status' => ProfileSourceStatus::Indexed->value,
                        'indexed_at' => now(),
                    ]);
                ProfileSourceItem::query()
                    ->where('profile_id', $profile->id)
                    ->where('approved', true)
                    ->update(['indexed' => false]);
                ProfileSourceItem::query()
                    ->where('profile_id', $profile->id)
                    ->whereIn('id', $indexedSourceItemIds)
                    ->update(['indexed' => true]);
                ProfileFact::query()
                    ->where('profile_id', $profile->id)
                    ->where('approved', true)
                    ->update(['indexed' => false]);
                ProfileFact::query()
                    ->where('profile_id', $profile->id)
                    ->whereIn('id', $indexedFactIds)
                    ->update(['indexed' => true]);
            });

            $totalChunks = $documents->count();
            $activeChunks = $documents->filter(fn (ProfileKnowledgeDocument $document): bool => $document->active)->count();
            $index->update([
                'status' => ProfileKnowledgeIndexStatus::Ready,
                'total_chunks' => $totalChunks,
                'active_chunks' => $activeChunks,
                'embedding_tokens' => (int) $index->embedding_tokens + $embeddingTokens,
                'last_error' => null,
                'completed_at' => now(),
            ]);

            Log::info('Profile knowledge indexing completed.', [
                'profile_id' => $profile->id,
                'total_chunks' => $totalChunks,
                'active_chunks' => $activeChunks,
                'embedded_chunks' => $embeddedChunks,
                'embedding_tokens' => $embeddingTokens,
                'duplicate_sources' => $duplicates,
            ]);

            return [
                'profile_id' => (int) $profile->id,
                'status' => ProfileKnowledgeIndexStatus::Ready->value,
                'total_chunks' => $totalChunks,
                'active_chunks' => $activeChunks,
                'embedded_chunks' => $embeddedChunks,
                'embedding_tokens' => $embeddingTokens,
            ];
        } catch (\Throwable $exception) {
            $index->update([
                'status' => ProfileKnowledgeIndexStatus::Failed,
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                'completed_at' => now(),
            ]);

            Log::error('Profile knowledge indexing failed.', [
                'profile_id' => $profile->id,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /** @param array<int, float> $vector */
    private function storeVector(ProfileKnowledgeChunk $chunk, array $vector, int $dimensions): void
    {
        if (count($vector) !== $dimensions) {
            throw new RuntimeException("Embedding dimensions did not match the configured {$dimensions} dimensions.");
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::update(
                'UPDATE profile_knowledge_chunks SET embedding = CAST(? AS vector) WHERE id = ?',
                [$this->vectorLiteral($vector), $chunk->id],
            );

            return;
        }

        DB::table('profile_knowledge_chunks')
            ->where('id', $chunk->id)
            ->update(['embedding' => json_encode($vector)]);
    }

    /** @param array<int, float> $vector */
    private function vectorLiteral(array $vector): string
    {
        foreach ($vector as $value) {
            if (! is_finite((float) $value)) {
                throw new RuntimeException('Embedding vector contains a non-finite value.');
            }
        }

        return '['.implode(',', array_map(fn ($value): string => (string) (float) $value, $vector)).']';
    }
}
