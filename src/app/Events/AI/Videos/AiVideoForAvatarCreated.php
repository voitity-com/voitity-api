<?php

namespace App\Events\AI\Videos;

use App\Models\AiImage;
use App\Models\AiVideo;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class AiVideoForAvatarCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public AiVideo $aiVideo, public ?AiImage $aiImage = null)
    {
        try {
            Log::info('AiVideoForAvatarCreated event instantiated', [
                'aivideo_id' => $aiVideo->id,
                'source_id' => $aiVideo->source_id,
                'user_id' => $aiVideo->user_id,
                'profile_id' => $aiVideo->profile_id,
                'aiimage_id' => $aiImage?->id,
            ]);
        } catch (Throwable $e) {
            Log::error('AiVideoForAvatarCreated event log failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
