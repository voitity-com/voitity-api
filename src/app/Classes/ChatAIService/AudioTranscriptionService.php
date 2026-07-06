<?php

namespace App\Classes\ChatAIService;

use Illuminate\Http\UploadedFile;
use RuntimeException;

class AudioTranscriptionService
{
    public function __construct(private readonly ChatAIClient $chatAIClient) {}

    public function transcribe(UploadedFile $audio): ChatAITextFromAudio
    {
        $tempBasePath = tempnam(sys_get_temp_dir(), 'audio_transcription_');

        if ($tempBasePath === false) {
            throw new RuntimeException('Unable to create temporary audio file for transcription.');
        }

        $extension = trim($audio->getClientOriginalExtension(), '.');
        $tempAudioPath = $extension !== '' ? "{$tempBasePath}.{$extension}" : $tempBasePath;

        if ($tempAudioPath !== $tempBasePath && ! rename($tempBasePath, $tempAudioPath)) {
            @unlink($tempBasePath);
            throw new RuntimeException('Unable to prepare temporary audio file for transcription.');
        }

        $sourcePath = $audio->getRealPath() ?: $audio->getPathname();

        if (! is_string($sourcePath) || ! copy($sourcePath, $tempAudioPath)) {
            @unlink($tempAudioPath);
            throw new RuntimeException('Unable to copy audio file for transcription.');
        }

        try {
            return $this->chatAIClient->getTextFromAudio($tempAudioPath);
        } finally {
            @unlink($tempAudioPath);
        }
    }
}
