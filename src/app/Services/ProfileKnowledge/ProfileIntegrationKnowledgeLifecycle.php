<?php

namespace App\Services\ProfileKnowledge;

use App\Models\ProfileIntegration;
use App\Models\ProfileKnowledgeChunk;
use Illuminate\Support\Facades\Log;

class ProfileIntegrationKnowledgeLifecycle
{
    public function __construct(private readonly ProfileKnowledgeIndexScheduler $scheduler) {}

    public function selectionChanged(ProfileIntegration $integration): void
    {
        $deselectedIds = $integration->media()
            ->where('selected', false)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        if ($deselectedIds !== []) {
            ProfileKnowledgeChunk::query()
                ->where('profile_id', $integration->profile_id)
                ->where('source_type', 'integration_media')
                ->whereIn('source_id', $deselectedIds)
                ->update(['active' => false]);
        }

        $this->scheduler->schedule((int) $integration->profile_id);

        Log::info('Integration media knowledge selection changed.', [
            'deselected_media_count' => count($deselectedIds),
            'profile_id' => $integration->profile_id,
            'provider' => $integration->provider,
        ]);
    }

    /** @param iterable<int, int|string> $mediaIds */
    public function forgetMedia(int $profileId, iterable $mediaIds, string $provider): void
    {
        $ids = collect($mediaIds)->map(fn ($id): string => (string) $id)->filter()->values()->all();

        if ($ids !== []) {
            ProfileKnowledgeChunk::query()
                ->where('profile_id', $profileId)
                ->where('source_type', 'integration_media')
                ->whereIn('source_id', $ids)
                ->delete();
        }

        $this->scheduler->schedule($profileId);

        Log::info('Integration media knowledge removed.', [
            'deleted_media_count' => count($ids),
            'profile_id' => $profileId,
            'provider' => $provider,
        ]);
    }
}
