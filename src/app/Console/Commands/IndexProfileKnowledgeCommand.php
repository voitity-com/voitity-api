<?php

namespace App\Console\Commands;

use App\Enums\ProfileKnowledgeIndexStatus;
use App\Jobs\ProfileKnowledge\IndexProfileKnowledge;
use App\Models\Profile;
use App\Services\ProfileKnowledge\ProfileKnowledgeIndexer;
use Illuminate\Console\Command;

class IndexProfileKnowledgeCommand extends Command
{
    protected $signature = 'ai-knowledge:index {profile? : Profile ID} {--sync : Run immediately} {--pending : Only missing, outdated or failed indexes} {--force : Re-embed unchanged content}';

    protected $description = 'Generate or refresh profile knowledge embeddings';

    public function handle(ProfileKnowledgeIndexer $indexer): int
    {
        $profileId = $this->argument('profile');
        $query = Profile::query()
            ->when($profileId, fn ($query) => $query->whereKey((int) $profileId))
            ->when($this->option('pending'), function ($query): void {
                $query->where(function ($query): void {
                    $query->whereDoesntHave('knowledgeIndex')
                        ->orWhereHas('knowledgeIndex', fn ($query) => $query->whereIn('status', [
                            ProfileKnowledgeIndexStatus::Pending->value,
                            ProfileKnowledgeIndexStatus::Outdated->value,
                            ProfileKnowledgeIndexStatus::Failed->value,
                        ])->orWhereNull('index_version')
                            ->orWhere('index_version', '!=', config('ai-knowledge.indexing.version'))
                            ->orWhere('embedding_model', '!=', config('ai-knowledge.embedding.model'))
                            ->orWhere('embedding_dimensions', '!=', config('ai-knowledge.embedding.dimensions')));
                });
            });

        if ($profileId && ! $query->exists()) {
            $this->error("Profile {$profileId} was not found.");

            return self::FAILURE;
        }

        $processed = 0;
        $failed = 0;

        $query->orderBy('id')->chunkById(100, function ($profiles) use (&$failed, &$processed, $indexer): void {
            foreach ($profiles as $profile) {
                try {
                    if ($this->option('sync')) {
                        $result = $indexer->index($profile, (bool) $this->option('force'));
                        $this->line("Profile {$profile->id}: {$result['status']}, {$result['total_chunks']} chunks, {$result['embedded_chunks']} embedded.");
                    } else {
                        IndexProfileKnowledge::dispatch((int) $profile->id);
                    }

                    $processed++;
                } catch (\Throwable $exception) {
                    $failed++;
                    $this->error("Profile {$profile->id}: {$exception->getMessage()}");
                }
            }
        });

        $this->info($this->option('sync')
            ? "Profiles indexed: {$processed}; failed: {$failed}."
            : "Profile indexing jobs queued: {$processed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
