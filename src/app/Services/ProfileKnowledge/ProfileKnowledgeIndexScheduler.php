<?php

namespace App\Services\ProfileKnowledge;

use App\Enums\ProfileKnowledgeIndexStatus;
use App\Jobs\ProfileKnowledge\IndexProfileKnowledge;
use App\Models\ProfileKnowledgeIndex;

class ProfileKnowledgeIndexScheduler
{
    public function schedule(int $profileId): void
    {
        if ($profileId <= 0) {
            return;
        }

        $index = ProfileKnowledgeIndex::query()->firstOrNew(['profile_id' => $profileId]);
        $index->status = $index->exists
            ? ProfileKnowledgeIndexStatus::Outdated
            : ProfileKnowledgeIndexStatus::Pending;
        $index->last_error = null;
        $index->save();

        IndexProfileKnowledge::dispatch($profileId)->afterCommit();
    }
}
