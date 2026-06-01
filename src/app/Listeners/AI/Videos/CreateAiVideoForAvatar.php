<?php

namespace App\Listeners\AI\Videos;

use App\Classes\VideoAIService\VideoAIService;
use App\Events\AI\Images\AiImageForAvatarGenerated;
use App\Events\AI\Videos\AiVideoForAvatarCreated;
use App\Models\AiVideo as AiVideoModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CreateAiVideoForAvatar implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(private readonly VideoAIService $videoAIService)
    {
    }

    public function handle(AiImageForAvatarGenerated $event): void
    {
        $aiImage = $event->aiImage->fresh();

        if (!$aiImage) {
            Log::warning('CreateAiVideoForAvatar skipped because AiImage no longer exists.');
            return;
        }

        try {
            $existingAiVideo = AiVideoModel::where('aiimage_id', $aiImage->id)
                ->orderByDesc('id')
                ->first();

            if ($existingAiVideo) {
                Log::info('CreateAiVideoForAvatar skipped because AiVideo already exists for AiImage.', [
                    'aiimage_id' => $aiImage->id,
                    'aivideo_id' => $existingAiVideo->id,
                    'source_id' => $existingAiVideo->source_id,
                    'status' => $existingAiVideo->status,
                ]);
                return;
            }

            Log::info('CreateAiVideoForAvatar listener triggered', [
                'aiimage_id' => $aiImage->id,
                'source_image_url' => $event->sourceImageUrl,
            ]);

            try {
                $aiVideo = AiVideoModel::create([
                    'user_id' => $aiImage->user_id,
                    'profile_id' => $aiImage->profile_id,
                    'aiimage_id' => $aiImage->id,
                    'source_id' => 'creating-' . Str::uuid()->toString(),
                    'source' => config('videoai.default', 'runway'),
                    'status' => 'creating',
                    'file' => null,
                ]);
            } catch (QueryException $e) {
                $existingAiVideo = AiVideoModel::where('aiimage_id', $aiImage->id)->first();

                if ($existingAiVideo) {
                    Log::info('CreateAiVideoForAvatar skipped because another job already created AiVideo.', [
                        'aiimage_id' => $aiImage->id,
                        'aivideo_id' => $existingAiVideo->id,
                        'source_id' => $existingAiVideo->source_id,
                        'status' => $existingAiVideo->status,
                    ]);
                    return;
                }

                throw $e;
            }

            $video = $this->videoAIService->createVideo(
                $event->sourceImageUrl,
                config('videoai.prompts.video')
            );

            if (!$video->id) {
                $aiVideo->status = 'failed';
                $aiVideo->save();
                throw new RuntimeException('Video AI video generation did not return a source id.');
            }

            $aiVideo->source_id = $video->id;
            $aiVideo->status = $this->normalizeStatus($video->status);
            $aiVideo->save();

            Log::info('AI video record created', [
                'aivideo_id' => $aiVideo->id,
                'aiimage_id' => $aiImage->id,
                'source_id' => $aiVideo->source_id,
            ]);

            event(new AiVideoForAvatarCreated($aiVideo, $aiImage));
        } catch (Throwable $e) {
            Log::error('CreateAiVideoForAvatar listener failed during processing', [
                'aiimage_id' => $aiImage->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(AiImageForAvatarGenerated $event, Throwable $exception): void
    {
        Log::error('CreateAiVideoForAvatar listener failed', [
            'aiimage_id' => $event->aiImage->id,
            'error' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'attempts' => $this->attempts(),
        ]);
    }

    private function normalizeStatus(string $status): string
    {
        return strtolower($status);
    }
}
