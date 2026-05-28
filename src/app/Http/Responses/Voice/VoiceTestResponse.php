<?php

namespace App\Http\Responses\Voice;

use App\Classes\VoiceService\VoiceClientGeneratedAudio;

class VoiceTestResponse
{
    public function __construct(private readonly VoiceClientGeneratedAudio $generatedAudio)
    {
    }

    public function toArray(): array
    {
        return [
            'voice_id' => $this->generatedAudio->voice->id,
            'profile_id' => $this->generatedAudio->voice->profile_id,
            'text' => $this->generatedAudio->text,
            'audio_url' => $this->generatedAudio->audioUrl,
            'audio_content' => $this->generatedAudio->audioContent,
            'audio_format' => $this->generatedAudio->audioFormat,
            'duration' => $this->generatedAudio->duration,
            'status' => $this->generatedAudio->status,
            'metadata' => $this->generatedAudio->metadata,
        ];
    }
}
