<?php

namespace App\Listeners\AI\Videos;

use App\Classes\VideoAIService\VideoAIArtifactStorage;
use App\Classes\VideoAIService\VideoAIService;
use App\Events\AI\Videos\AiVideoForAvatarCreated;
use App\Models\ProfileAvatar;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class GetAIVideoForAvatar implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 20;
    public int $backoff = 30;
    public int $timeout = 120;

    public function __construct(
        private readonly VideoAIService $videoAIService,
        private readonly VideoAIArtifactStorage $artifactStorage
    ) {
    }

    public function handle(AiVideoForAvatarCreated $event): void
    {
        $aiVideo = $event->aiVideo->fresh();

        if (!$aiVideo) {
            Log::warning('GetAIVideoForAvatar skipped because AiVideo no longer exists.');
            return;
        }

        if ($aiVideo->status === 'succeeded' && $aiVideo->file) {
            $this->updateProfileAvatar($event, $aiVideo);

            Log::info('GetAIVideoForAvatar skipped because AiVideo is already stored.', [
                'aivideo_id' => $aiVideo->id,
                'source_id' => $aiVideo->source_id,
                'file' => $aiVideo->file,
            ]);
            return;
        }

        try {
            Log::info('GetAIVideoForAvatar listener triggered', [
                'aivideo_id' => $aiVideo->id,
                'source_id' => $aiVideo->source_id,
                'attempt' => $this->attempts(),
            ]);

            $video = $this->videoAIService->getVideo($aiVideo->source_id);
            $status = $this->normalizeStatus($video->status);
            $aiVideo->status = $status;

            if ($video->isSuccessful() && $video->getOutputUrl()) {
                $file = $this->artifactStorage->storeVideoFromUrl($video->getOutputUrl(), $aiVideo->id);

                $aiVideo->status = 'succeeded';
                $aiVideo->file = $file;
                $aiVideo->save();

                $this->updateProfileAvatar($event, $aiVideo);

                Log::info('AI video generated and stored', [
                    'aivideo_id' => $aiVideo->id,
                    'file' => $file,
                ]);
                return;
            }

            if ($video->isFailed()) {
                $aiVideo->status = 'failed';
                $aiVideo->save();

                Log::error('AI video generation failed at provider', [
                    'aivideo_id' => $aiVideo->id,
                    'source_id' => $aiVideo->source_id,
                    'response' => $video->getResponse(),
                ]);
                return;
            }

            $aiVideo->save();
            $this->releaseOrMarkFailed($aiVideo);
        } catch (Throwable $e) {
            Log::error('GetAIVideoForAvatar listener failed during processing', [
                'aivideo_id' => $aiVideo->id,
                'source_id' => $aiVideo->source_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(AiVideoForAvatarCreated $event, Throwable $exception): void
    {
        $aiVideo = $event->aiVideo->fresh();

        if ($aiVideo) {
            $aiVideo->status = 'failed';
            $aiVideo->save();
        }

        Log::error('GetAIVideoForAvatar listener failed', [
            'aivideo_id' => $event->aiVideo->id,
            'source_id' => $event->aiVideo->source_id,
            'error' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'attempts' => $this->attempts(),
        ]);
    }

    private function updateProfileAvatar(AiVideoForAvatarCreated $event, $aiVideo): void
    {
        if (!$aiVideo->profile_id) {
            return;
        }

        $avatar = ProfileAvatar::where('profile_id', $aiVideo->profile_id)->first();

        if (!$avatar) {
            $avatar = new ProfileAvatar([
                'user_id' => $aiVideo->user_id,
                'profile_id' => $aiVideo->profile_id,
                'aiimage_id' => $event->aiImage?->id,
                'status' => 'active',
            ]);
        }

        $avatar->ai_video_id = $aiVideo->id;
        $avatar->file = $aiVideo->file;
        $avatar->save();
    }

    private function releaseOrMarkFailed($aiVideo): void
    {
        if ($this->attempts() >= $this->tries) {
            $aiVideo->status = 'failed';
            $aiVideo->save();

            Log::error('AI video generation exceeded max attempts', [
                'aivideo_id' => $aiVideo->id,
                'source_id' => $aiVideo->source_id,
                'attempts' => $this->attempts(),
            ]);
            return;
        }

        Log::info('AI video not ready, releasing job', [
            'aivideo_id' => $aiVideo->id,
            'source_id' => $aiVideo->source_id,
            'delay' => $this->backoff,
            'attempt' => $this->attempts(),
        ]);

        if ($this->job) {
            $this->release($this->backoff);
        }
    }

    private function normalizeStatus(string $status): string
    {
        return strtolower($status);
    }
}
