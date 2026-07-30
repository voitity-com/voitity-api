<?php

namespace App\Classes\ChatAIService;

use getID3;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class AudioMessageInspector
{
    public function __construct(private readonly mixed $getId3 = null) {}

    public function durationSeconds(UploadedFile $audio): int
    {
        $analyzer = $this->getId3 ?? new getID3;
        $info = $analyzer->analyze($audio->getPathname());
        $duration = $info['playtime_seconds'] ?? null;

        if (! is_numeric($duration) || (float) $duration <= 0) {
            throw new RuntimeException('Audio duration could not be determined.');
        }

        return (int) ceil((float) $duration);
    }
}
