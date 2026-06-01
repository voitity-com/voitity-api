<?php

namespace App\Events\AI\Images;

use App\Models\AiImage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiImageForAvatarGenerated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public AiImage $aiImage, public string $sourceImageUrl)
    {
        try {
            Log::info('AiImageForAvatarGenerated event instantiated', [
                'aiimage_id' => $aiImage->id,
                'source_id' => $aiImage->source_id,
                'file' => $aiImage->file,
                'source_image_url' => $sourceImageUrl,
            ]);
        } catch (Throwable $e) {
            Log::error('AiImageForAvatarGenerated event log failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
