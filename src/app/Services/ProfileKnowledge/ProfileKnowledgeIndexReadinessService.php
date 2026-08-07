<?php

namespace App\Services\ProfileKnowledge;

use App\Enums\ProfileKnowledgeIndexStatus;
use App\Models\Profile;
use App\Models\ProfileKnowledgeIndex;

class ProfileKnowledgeIndexReadinessService
{
    /**
     * @return array{ready:bool,reason:?string,index:?ProfileKnowledgeIndex}
     */
    public function inspect(Profile $profile): array
    {
        $index = $profile->knowledgeIndex()->first();

        if (! $index instanceof ProfileKnowledgeIndex) {
            return ['ready' => false, 'reason' => 'index_missing', 'index' => null];
        }

        if ($index->status !== ProfileKnowledgeIndexStatus::Ready) {
            return ['ready' => false, 'reason' => 'index_'.$index->status->value, 'index' => $index];
        }

        if ($index->index_version !== config('ai-knowledge.indexing.version')) {
            return ['ready' => false, 'reason' => 'index_version_mismatch', 'index' => $index];
        }

        if ($index->embedding_model !== config('ai-knowledge.embedding.model')) {
            return ['ready' => false, 'reason' => 'index_model_mismatch', 'index' => $index];
        }

        if ((int) $index->embedding_dimensions !== (int) config('ai-knowledge.embedding.dimensions')) {
            return ['ready' => false, 'reason' => 'index_dimensions_mismatch', 'index' => $index];
        }

        return ['ready' => true, 'reason' => null, 'index' => $index];
    }
}
