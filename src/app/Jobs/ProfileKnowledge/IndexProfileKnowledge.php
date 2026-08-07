<?php

namespace App\Jobs\ProfileKnowledge;

use App\Models\Profile;
use App\Services\ProfileKnowledge\ProfileKnowledgeIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class IndexProfileKnowledge implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $profileId) {}

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("profile-knowledge:{$this->profileId}"))->releaseAfter(15)->expireAfter(600)];
    }

    public function handle(ProfileKnowledgeIndexer $indexer): void
    {
        $profile = Profile::query()->find($this->profileId);

        if ($profile instanceof Profile) {
            $indexer->index($profile);
        }
    }
}
