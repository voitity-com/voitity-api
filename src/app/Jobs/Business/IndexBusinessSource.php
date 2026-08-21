<?php

namespace App\Jobs\Business;

use App\Models\BusinessSource;
use App\Services\Business\BusinessKnowledgeIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class IndexBusinessSource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $sourceId) {}

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("business-source:{$this->sourceId}"))->releaseAfter(15)->expireAfter(600)];
    }

    public function handle(BusinessKnowledgeIndexer $indexer): void
    {
        $source = BusinessSource::query()->find($this->sourceId);
        if ($source instanceof BusinessSource) {
            $indexer->index($source);
        }
    }
}
