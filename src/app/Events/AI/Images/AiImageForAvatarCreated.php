<?php

namespace App\Events\AI\Images;

use App\Models\AiImage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiImageForAvatarCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public AiImage $aiImage)
    {
        try {
            Log::info('AiImageForAvatarCreated event instantiated', [
                'aiimage_id' => $aiImage->id,
                'source_id' => $aiImage->source_id,
                'user_id' => $aiImage->user_id,
                'profile_id' => $aiImage->profile_id,
            ]);
        } catch (Throwable $e) {
            Log::error('AiImageForAvatarCreated event log failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
