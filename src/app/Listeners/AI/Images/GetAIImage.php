<?php

namespace App\Listeners\AI\Images;

use App\Classes\VideoAIService\VideoAIArtifactStorage;
use App\Classes\VideoAIService\VideoAIService;
use App\Events\AI\Images\AiImageCreated;
use App\Events\AI\Images\AiImageGenerated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class GetAIImage implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 5;
    public int $backoff = 30;
    public int $timeout = 120;

    public function __construct(
        private readonly VideoAIService $videoAIService,
        private readonly VideoAIArtifactStorage $artifactStorage
    ) {
    }

    public function handle(AiImageCreated $event): void
    {
        $aiImage = $event->aiImage->fresh();

        if (!$aiImage) {
            Log::warning('GetAIImage skipped because AiImage no longer exists.');
            return;
        }

        try {
            Log::info('GetAIImage listener triggered', [
                'aiimage_id' => $aiImage->id,
                'source_id' => $aiImage->source_id,
                'attempt' => $this->attempts(),
            ]);

            $image = $this->videoAIService->getImage($aiImage->source_id);
            $status = $this->normalizeStatus($image->status);
            $aiImage->status = $status;

            if ($image->isSuccessful() && $image->getOutputUrl()) {
                $file = $this->artifactStorage->storeImageFromUrl($image->getOutputUrl(), $aiImage->id);

                $aiImage->status = 'succeeded';
                $aiImage->file = $file;
                $aiImage->save();

                Log::info('AI image generated and stored', [
                    'aiimage_id' => $aiImage->id,
                    'file' => $file,
                ]);

                event(new AiImageGenerated($aiImage->fresh(), $image->getOutputUrl()));
                return;
            }

            if ($image->isFailed()) {
                $aiImage->status = 'failed';
                $aiImage->save();

                Log::error('AI image generation failed at provider', [
                    'aiimage_id' => $aiImage->id,
                    'source_id' => $aiImage->source_id,
                    'response' => $image->getResponse(),
                ]);
                return;
            }

            $aiImage->save();
            $this->releaseOrMarkFailed($aiImage);
        } catch (Throwable $e) {
            Log::error('GetAIImage listener failed during processing', [
                'aiimage_id' => $aiImage->id,
                'source_id' => $aiImage->source_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(AiImageCreated $event, Throwable $exception): void
    {
        $aiImage = $event->aiImage->fresh();

        if ($aiImage) {
            $aiImage->status = 'failed';
            $aiImage->save();
        }

        Log::error('GetAIImage listener failed', [
            'aiimage_id' => $event->aiImage->id,
            'source_id' => $event->aiImage->source_id,
            'error' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'attempts' => $this->attempts(),
        ]);
    }

    private function releaseOrMarkFailed($aiImage): void
    {
        if ($this->attempts() >= $this->tries) {
            $aiImage->status = 'failed';
            $aiImage->save();

            Log::error('AI image generation exceeded max attempts', [
                'aiimage_id' => $aiImage->id,
                'source_id' => $aiImage->source_id,
                'attempts' => $this->attempts(),
            ]);
            return;
        }

        Log::info('AI image not ready, releasing job', [
            'aiimage_id' => $aiImage->id,
            'source_id' => $aiImage->source_id,
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
