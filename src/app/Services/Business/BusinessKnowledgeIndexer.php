<?php

namespace App\Services\Business;

use App\Classes\EmbeddingService\EmbeddingClient;
use App\Enums\BusinessSourceStatus;
use App\Models\BusinessKnowledgeChunk;
use App\Models\BusinessSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class BusinessKnowledgeIndexer
{
    public function __construct(
        private readonly EmbeddingClient $embeddings,
        private readonly BusinessKnowledgeChunker $chunker,
        private readonly BusinessUsageRecorder $usage,
    ) {}

    /** @return array{source_id:int,status:string,chunks:int,embedding_tokens:int,model:string} */
    public function index(BusinessSource $source): array
    {
        $source->update([
            'status' => BusinessSourceStatus::Processing,
            'last_error' => null,
            'indexed_at' => null,
        ]);

        Log::info('Business source indexing started.', [
            'business_id' => $source->business_id,
            'source_id' => $source->id,
            'source_type' => $source->type,
        ]);

        try {
            $documents = $this->chunker->chunk((string) $source->extracted_text);
            if ($documents === []) {
                throw new RuntimeException('Business source does not contain indexable text.');
            }

            $vectors = [];
            $embeddingTokens = 0;
            $provider = '';
            $model = (string) config('ai-knowledge.embedding.model', 'text-embedding-3-small');
            $batchSize = (int) config('business-ai.knowledge.embedding_batch_size', 40);

            foreach (array_chunk($documents, $batchSize) as $batch) {
                $result = $this->embeddings->embed($batch);
                $vectors = [...$vectors, ...$result->vectors];
                $embeddingTokens += $result->inputTokens;
                $provider = $result->source;
                $model = $result->model;
            }

            $dimensions = (int) config('ai-knowledge.embedding.dimensions', 1536);
            if (count($vectors) !== count($documents)) {
                throw new RuntimeException('Business source embeddings did not match the generated chunks.');
            }

            DB::transaction(function () use ($dimensions, $documents, $model, $source, $vectors): void {
                $source->chunks()->delete();

                foreach ($documents as $index => $content) {
                    $vector = $vectors[$index] ?? [];
                    if (count($vector) !== $dimensions) {
                        throw new RuntimeException("Embedding dimensions did not match the configured {$dimensions} dimensions.");
                    }

                    $chunk = $source->chunks()->create([
                        'business_id' => $source->business_id,
                        'chunk_key' => 'chunk-'.($index + 1),
                        'content' => $content,
                        'content_hash' => hash('sha256', $content),
                        'token_count' => $this->usage->estimateTokens($content),
                        'metadata' => ['source_name' => $source->name, 'position' => $index + 1],
                        'active' => true,
                        'embedding_model' => $model,
                        'embedding_dimensions' => $dimensions,
                        'embedded_at' => now(),
                    ]);
                    $this->storeVector($chunk, $vector);
                }

                $source->update([
                    'status' => BusinessSourceStatus::Indexed,
                    'last_error' => null,
                    'indexed_at' => now(),
                ]);
            });

            $this->usage->record([
                'business_id' => $source->business_id,
                'business_source_id' => $source->id,
                'event_type' => 'source_indexed',
                'provider' => $provider ?: 'unknown',
                'model' => $model,
                'input_tokens' => $embeddingTokens,
                'metadata' => ['chunks' => count($documents), 'embedding_dimensions' => $dimensions],
            ]);

            Log::info('Business source indexing completed.', [
                'business_id' => $source->business_id,
                'source_id' => $source->id,
                'chunks' => count($documents),
                'embedding_tokens' => $embeddingTokens,
                'provider' => $provider,
                'model' => $model,
            ]);

            return [
                'source_id' => (int) $source->id,
                'status' => BusinessSourceStatus::Indexed->value,
                'chunks' => count($documents),
                'embedding_tokens' => $embeddingTokens,
                'model' => $model,
            ];
        } catch (\Throwable $exception) {
            $source->update([
                'status' => BusinessSourceStatus::Failed,
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
                'indexed_at' => null,
            ]);

            Log::error('Business source indexing failed.', [
                'business_id' => $source->business_id,
                'source_id' => $source->id,
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    /** @param array<int, float> $vector */
    private function storeVector(BusinessKnowledgeChunk $chunk, array $vector): void
    {
        foreach ($vector as $value) {
            if (! is_finite((float) $value)) {
                throw new RuntimeException('Embedding vector contains a non-finite value.');
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::update(
                'UPDATE business_knowledge_chunks SET embedding = CAST(? AS vector) WHERE id = ?',
                ['['.implode(',', array_map(fn ($value): string => (string) (float) $value, $vector)).']', $chunk->id],
            );

            return;
        }

        DB::table('business_knowledge_chunks')
            ->where('id', $chunk->id)
            ->update(['embedding' => json_encode($vector)]);
    }
}
