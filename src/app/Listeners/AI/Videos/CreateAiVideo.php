<?php

namespace App\Listeners\AI\Videos;

use App\Classes\VideoAIService\VideoAIService;
use App\Events\AI\Images\AiImageGenerated;
use App\Events\AI\Videos\AiVideoCreated;
use App\Models\AiVideo as AiVideoModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CreateAiVideo implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(private readonly VideoAIService $videoAIService)
    {
    }

    public function handle(AiImageGenerated $event): void
    {
        $aiImage = $event->aiImage->fresh();

        if (!$aiImage) {
            Log::warning('CreateAiVideo skipped because AiImage no longer exists.');
            return;
        }

        try {
            Log::info('CreateAiVideo listener triggered', [
                'aiimage_id' => $aiImage->id,
                'source_image_url' => $event->sourceImageUrl,
            ]);

            $video = $this->videoAIService->createVideo(
                $event->sourceImageUrl,
                config('videoai.prompts.video')
            );

            if (!$video->id) {
                throw new RuntimeException('Video AI video generation did not return a source id.');
            }

            $aiVideo = AiVideoModel::create([
                'user_id' => $aiImage->user_id,
                'profile_id' => $aiImage->profile_id,
                'source_id' => $video->id,
                'source' => config('videoai.default', 'runway'),
                'status' => $this->normalizeStatus($video->status),
                'file' => null,
            ]);

            Log::info('AI video record created', [
                'aivideo_id' => $aiVideo->id,
                'aiimage_id' => $aiImage->id,
                'source_id' => $aiVideo->source_id,
            ]);

            event(new AiVideoCreated($aiVideo, $aiImage));
        } catch (Throwable $e) {
            Log::error('CreateAiVideo listener failed during processing', [
                'aiimage_id' => $aiImage->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(AiImageGenerated $event, Throwable $exception): void
    {
        Log::error('CreateAiVideo listener failed', [
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
