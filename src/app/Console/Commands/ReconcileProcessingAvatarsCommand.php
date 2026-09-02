<?php

namespace App\Console\Commands;

use App\Events\AI\Images\AiImageForAvatarCreated;
use App\Events\AI\Images\AiImageForAvatarGenerated;
use App\Events\AI\Videos\AiVideoForAvatarCreated;
use App\Models\AiImage;
use App\Models\AiVideo;
use App\Models\ProfileAvatar;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileProcessingAvatarsCommand extends Command
{
    protected $signature = 'avatars:reconcile-processing
        {--profile= : Restrict recovery to one profile ID}
        {--older-than= : Minimum age in minutes before an avatar is recovered}
        {--limit= : Maximum number of avatars to inspect}';

    protected $description = 'Requeue stale avatar generations that remain in processing';

    public function handle(): int
    {
        $olderThan = max(0, (int) ($this->option('older-than') ?? config('videoai.avatar_recovery.stale_after_minutes', 15)));
        $limit = max(1, min(500, (int) ($this->option('limit') ?? config('videoai.avatar_recovery.batch_size', 100))));
        $cutoff = now()->subMinutes($olderThan);
        $profileId = $this->option('profile');
        $requeued = 0;
        $skipped = 0;
        $failed = 0;

        $avatars = ProfileAvatar::query()
            ->with(['aiImage.aiVideos'])
            ->where('status', ProfileAvatar::STATUS_PROCESSING)
            ->where('updated_at', '<=', $cutoff)
            ->when($profileId, fn ($query) => $query->where('profile_id', (int) $profileId))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();

        foreach ($avatars as $avatar) {
            $claimed = ProfileAvatar::query()
                ->whereKey($avatar->id)
                ->where('status', ProfileAvatar::STATUS_PROCESSING)
                ->where('updated_at', '<=', $cutoff)
                ->update(['updated_at' => now()]);

            if ($claimed !== 1) {
                $skipped++;

                continue;
            }

            try {
                if (! $this->requeue($avatar)) {
                    $skipped++;

                    Log::warning('Stale avatar generation could not be requeued.', [
                        'profile_avatar_id' => $avatar->id,
                        'profile_id' => $avatar->profile_id,
                        'aiimage_id' => $avatar->aiimage_id,
                    ]);

                    continue;
                }

                $requeued++;
            } catch (Throwable $exception) {
                $failed++;

                Log::error('Stale avatar generation recovery failed.', [
                    'profile_avatar_id' => $avatar->id,
                    'profile_id' => $avatar->profile_id,
                    'aiimage_id' => $avatar->aiimage_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->info("Stale avatar generations requeued: {$requeued}; skipped: {$skipped}; failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function requeue(ProfileAvatar $avatar): bool
    {
        $aiImage = $avatar->aiImage;

        if (! $aiImage) {
            return false;
        }

        if ($aiImage->status !== 'succeeded' || blank($aiImage->file)) {
            return $this->requeueImage($aiImage);
        }

        /** @var AiVideo|null $aiVideo */
        $aiVideo = $aiImage->aiVideos->sortByDesc('id')->first();

        if (! $aiVideo) {
            event(new AiImageForAvatarGenerated($aiImage, $aiImage->file));

            return true;
        }

        if ($aiVideo->status === 'failed' || blank($aiVideo->source_id) || str_starts_with($aiVideo->source_id, 'creating-')) {
            return false;
        }

        event(new AiVideoForAvatarCreated($aiVideo, $aiImage));

        return true;
    }

    private function requeueImage(AiImage $aiImage): bool
    {
        if ($aiImage->status === 'failed' || blank($aiImage->source_id)) {
            return false;
        }

        event(new AiImageForAvatarCreated($aiImage));

        return true;
    }
}
