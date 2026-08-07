<?php

namespace App\Services\ProfileKnowledge;

use App\Models\Profile;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ProfileKnowledgePromptContextService
{
    public function __construct(
        private readonly ProfileKnowledgeIndexReadinessService $readiness,
        private readonly ProfileKnowledgeIndexer $indexer,
        private readonly ProfileKnowledgeRetriever $retriever,
    ) {}

    public function build(
        Profile $profile,
        string $message,
        ?int $chatId = null,
        ?int $currentMessageId = null,
    ): ProfileKnowledgePromptContext {
        $readiness = $this->readiness->inspect($profile);

        if (! $readiness['ready']) {
            Log::info('Profile knowledge index is not ready; rebuilding before retrieval.', [
                'profile_id' => $profile->id,
                'reason' => $readiness['reason'],
            ]);

            $this->indexer->index($profile);
            $readiness = $this->readiness->inspect($profile);
        }

        if (! $readiness['ready'] || ! $readiness['index']) {
            throw new RuntimeException(
                'Profile knowledge index could not be prepared for embedding retrieval.'
            );
        }

        return new ProfileKnowledgePromptContext(
            retrieval: $this->retriever->retrieve(
                $profile,
                $this->retrievalQuery($profile, $message, $chatId, $currentMessageId)
            ),
            indexId: (int) $readiness['index']->id,
        );
    }

    private function retrievalQuery(Profile $profile, string $message, ?int $chatId, ?int $currentMessageId): string
    {
        if (! $profile->exists || ! $chatId) {
            return $message;
        }

        $history = $profile->messages()
            ->where('chat_id', $chatId)
            ->when($currentMessageId, fn ($query) => $query->where('id', '!=', $currentMessageId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(2)
            ->pluck('text')
            ->reverse()
            ->filter(fn ($text): bool => filled($text))
            ->values()
            ->all();

        return implode("\n", [...$history, $message]);
    }
}
