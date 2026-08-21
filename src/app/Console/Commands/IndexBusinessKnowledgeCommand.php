<?php

namespace App\Console\Commands;

use App\Models\BusinessSource;
use App\Services\Business\BusinessKnowledgeIndexer;
use Illuminate\Console\Command;

class IndexBusinessKnowledgeCommand extends Command
{
    protected $signature = 'business:reindex-sources {business? : Business ID} {--source= : Single source ID}';

    protected $description = 'Generate or refresh Business source chunks and embeddings';

    public function handle(BusinessKnowledgeIndexer $indexer): int
    {
        $query = BusinessSource::query()->orderBy('id');
        if ($businessId = $this->argument('business')) {
            $query->where('business_id', (int) $businessId);
        }
        if ($sourceId = $this->option('source')) {
            $query->whereKey((int) $sourceId);
        }

        $sources = $query->get();
        if ($sources->isEmpty()) {
            $this->warn('No Business sources matched the requested scope.');

            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            $this->line("Indexing Business {$source->business_id} source {$source->id}: {$source->name}");
            $result = $indexer->index($source);
            $this->info("Indexed {$result['chunks']} chunks with {$result['embedding_tokens']} embedding tokens.");
        }

        return self::SUCCESS;
    }
}
