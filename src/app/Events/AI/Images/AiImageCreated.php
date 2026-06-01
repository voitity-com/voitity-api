<?php

namespace App\Events\AI\Images;

use App\Models\AiImage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiImageCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public AiImage $aiImage)
    {
        try {
            Log::info('AiImageCreated event instantiated', [
                'aiimage_id' => $aiImage->id,
                'source_id' => $aiImage->source_id,
                'user_id' => $aiImage->user_id,
                'profile_id' => $aiImage->profile_id,
            ]);
        } catch (Throwable $e) {
            Log::error('AiImageCreated event log failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
