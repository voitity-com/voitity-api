<?php

namespace App\Services\ProfileKnowledge;

use App\Classes\ProfileKnowledge\ProfileDataSynchronizer;
use App\Models\ProfileKnowledgeChunk;
use App\Models\ProfileSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProfileSourceLifecycleService
{
    public function __construct(
        private readonly ProfileDataSynchronizer $synchronizer,
        private readonly ProfileKnowledgeIndexScheduler $scheduler,
    ) {}

    public function delete(ProfileSource $source): void
    {
        $source->loadMissing(['profile', 'items']);
        $profile = $source->profile;
        $profileId = (int) $source->profile_id;
        $sourceId = (int) $source->id;
        $itemIds = $source->items()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $factIds = $source->facts()->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $disk = (string) (data_get($source->metadata, 'file.disk') ?: config('profile-knowledge-ai.sources.disk', 'profiles'));
        $path = trim((string) $source->storage_path);

        DB::transaction(function () use ($factIds, $itemIds, $profile, $profileId, $source, $sourceId): void {
            ProfileKnowledgeChunk::query()
                ->where('profile_id', $profileId)
                ->where(function ($query) use ($factIds, $itemIds, $sourceId): void {
                    $query->where(fn ($nested) => $nested
                        ->where('source_type', 'profile_source')
                        ->where('source_id', (string) $sourceId));

                    if ($itemIds !== []) {
                        $query->orWhere(fn ($nested) => $nested
                            ->where('source_type', 'profile_source_item')
                            ->whereIn('source_id', array_map('strval', $itemIds)));
                    }

                    if ($factIds !== []) {
                        $query->orWhere(fn ($nested) => $nested
                            ->where('source_type', 'profile_fact')
                            ->whereIn('source_id', array_map('strval', $factIds)));
                    }

                    $query->orWhere('source_type', 'profile_data');
                })
                ->delete();

            $this->synchronizer->removeSource($profile, $source);
            $source->facts()->delete();
            $source->deleteQuietly();
        });

        $this->scheduler->schedule($profileId);

        if ($path !== '') {
            try {
                Storage::disk($disk)->delete($path);
            } catch (Throwable $exception) {
                Log::warning('Profile source file cleanup failed after source deletion.', [
                    'disk' => $disk,
                    'message' => $exception->getMessage(),
                    'path' => $path,
                    'profile_id' => $profileId,
                    'source_id' => $sourceId,
                ]);
            }
        }

        Log::info('Profile source deleted with its knowledge chunks.', [
            'facts_deleted' => count($factIds),
            'items_deleted' => count($itemIds),
            'profile_id' => $profileId,
            'source_id' => $sourceId,
        ]);
    }
}
