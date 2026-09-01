<?php

namespace App\Jobs\ProfileKnowledge;

use App\Classes\ProfileKnowledge\ProfileDataSynchronizer;
use App\Enums\ActivationEventType;
use App\Enums\ProfileSourceStatus;
use App\Models\ProfileSource;
use App\Models\User;
use App\Services\Activation\ActivationEventRecorder;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\ProfileKnowledge\ProfileKnowledgeIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SynchronizeProfileSource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $sourceId) {}

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        $profileId = (int) ProfileSource::query()->whereKey($this->sourceId)->value('profile_id');

        return [(new WithoutOverlapping("profile-knowledge:{$profileId}"))->releaseAfter(15)->expireAfter(600)];
    }

    public function handle(
        ProfileDataSynchronizer $synchronizer,
        ProfileKnowledgeIndexer $indexer,
        ?ActivationEventRecorder $activationEvents = null,
    ): void {
        $source = ProfileSource::query()->with(['profile', 'items'])->find($this->sourceId);

        if (! $source instanceof ProfileSource || $source->status === ProfileSourceStatus::Duplicate) {
            return;
        }

        ProfileSource::query()->whereKey($source->id)->update([
            'status' => ProfileSourceStatus::Syncing->value,
            'processing_stage' => 'synchronizing',
            'last_error' => null,
            'retry_count' => DB::raw('retry_count + 1'),
            'processing_started_at' => $source->processing_started_at ?? now(),
            'processing_completed_at' => null,
        ]);

        Log::info('Profile source synchronization started.', [
            'attempt' => max(1, $this->attempts()),
            'profile_id' => $source->profile_id,
            'source_id' => $source->id,
        ]);

        try {
            DB::transaction(function () use ($source, $synchronizer): void {
                $now = now();

                ProfileSource::query()->whereKey($source->id)->update([
                    'status' => ProfileSourceStatus::Approved->value,
                    'processing_stage' => 'approving',
                    'approved_at' => $now,
                    'indexed_at' => null,
                    'last_synced_at' => $now,
                ]);
                $source->items()->update(['approved' => true, 'indexed' => false]);
                $source->facts()->update(['approved' => true, 'indexed' => false]);
                $synchronizer->syncApprovedSource($source->profile, $source->fresh(['items']));
            });

            ProfileSource::query()->whereKey($source->id)->update([
                'status' => ProfileSourceStatus::Indexing->value,
                'processing_stage' => 'indexing',
            ]);

            $indexer->index($source->profile->fresh());

            ProfileSource::query()->whereKey($source->id)->update([
                'status' => ProfileSourceStatus::Indexed->value,
                'processing_stage' => 'completed',
                'last_error' => null,
                'processing_completed_at' => now(),
                'indexed_at' => now(),
            ]);

            $source->loadMissing(['profile', 'user']);

            if ($source->user instanceof User) {
                ($activationEvents ?? app(ActivationEventRecorder::class))->record(
                    $source->user,
                    ActivationEventType::SourceSynchronized,
                    "profile:{$source->profile_id}:source-synchronized",
                    profile: $source->profile,
                    metadata: ['source_id' => $source->id],
                );
            }

            $this->notifySuccess($source->fresh());

            Log::info('Profile source synchronization completed.', [
                'profile_id' => $source->profile_id,
                'source_id' => $source->id,
            ]);
        } catch (Throwable $exception) {
            ProfileSource::query()->whereKey($source->id)->update([
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);

            Log::warning('Profile source synchronization attempt failed.', [
                'attempt' => max(1, $this->attempts()),
                'message' => $exception->getMessage(),
                'profile_id' => $source->profile_id,
                'source_id' => $source->id,
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $source = ProfileSource::query()->with(['profile', 'user'])->find($this->sourceId);

        if (! $source instanceof ProfileSource) {
            return;
        }

        $message = mb_substr($exception?->getMessage() ?: 'The source could not be synchronized.', 0, 2000);
        ProfileSource::query()->whereKey($source->id)->update([
            'status' => ProfileSourceStatus::Failed->value,
            'processing_stage' => 'failed',
            'last_error' => $message,
            'processing_completed_at' => now(),
        ]);

        if ($source->user instanceof User) {
            app(NotificationDispatcher::class)->send($source->user, 'source_rejected_or_failed', [
                ...$this->notificationData($source),
                'reason' => $message,
            ]);
        }

        Log::error('Profile source synchronization failed permanently.', [
            'message' => $message,
            'profile_id' => $source->profile_id,
            'source_id' => $source->id,
        ]);
    }

    private function notifySuccess(ProfileSource $source): void
    {
        $user = User::query()->find($source->user_id);

        if (! $user instanceof User) {
            return;
        }

        $dispatcher = app(NotificationDispatcher::class);
        $dispatcher->sendInApp($user, 'source_approved', $this->notificationData($source));
        $dispatcher->sendInApp($user, 'source_synchronized', $this->notificationData($source));
    }

    /** @return array<string, mixed> */
    private function notificationData(ProfileSource $source): array
    {
        return [
            'profile' => $source->profile?->name ?: "Profile {$source->profile_id}",
            'profile_id' => $source->profile_id,
            'source' => $source->name ?: ($source->original_filename ?: "Source {$source->id}"),
            'source_id' => $source->id,
            'action_url' => "/dashboard/profiles/{$source->profile_id}/sources",
        ];
    }
}
