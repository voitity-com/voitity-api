<?php

namespace App\Classes\VoiceService;

use App\Models\Voice;

class VoiceClientGeneratedAudio
{
    /**
     * The voice used for generation.
     *
     * @var Voice
     */
    public $voice;

    /**
     * The text that was converted to audio.
     *
     * @var string
     */
    public $text;

    /**
     * The generated audio file URL or path.
     *
     * @var string|null
     */
    public $audioUrl;

    /**
     * The generated audio file content (base64 or binary).
     *
     * @var string|null
     */
    public $audioContent;

    /**
     * The audio format (mp3, wav, etc.).
     *
     * @var string
     */
    public $audioFormat;

    /**
     * The duration of the generated audio in seconds.
     *
     * @var float|null
     */
    public $duration;

    /**
     * The status of the generation operation.
     *
     * @var string
     */
    public $status;

    /**
     * Additional metadata from the generation operation.
     *
     * @var array
     */
    public $metadata;

    /**
     * Create a new VoiceClientGeneratedAudio instance.
     *
     * @param Voice $voice
     * @param string $text
     * @param string|null $audioUrl
     * @param string|null $audioContent
     * @param string $audioFormat
     * @param float|null $duration
     * @param string $status
     * @param array $metadata
     */
    public function __construct(
        Voice $voice,
        string $text,
        ?string $audioUrl = null,
        ?string $audioContent = null,
        string $audioFormat = 'mp3',
        ?float $duration = null,
        string $status = 'pending',
        array $metadata = []
    ) {
        $this->voice = $voice;
        $this->text = $text;
        $this->audioUrl = $audioUrl;
        $this->audioContent = $audioContent;
        $this->audioFormat = $audioFormat;
        $this->duration = $duration;
        $this->status = $status;
        $this->metadata = $metadata;
    }

    /**
     * Check if the generation operation was successful.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'completed' || $this->status === 'success';
    }

    /**
     * Check if the generation operation failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed' || $this->status === 'error';
    }

    /**
     * Check if the generation operation is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === 'pending' || $this->status === 'processing';
    }

    /**
     * Check if audio content is available.
     *
     * @return bool
     */
    public function hasAudioContent(): bool
    {
        return !empty($this->audioContent) || !empty($this->audioUrl);
    }

    /**
     * Get the audio URL.
     *
     * @return string|null
     */
    public function getAudioUrl(): ?string
    {
        return $this->audioUrl;
    }

    /**
     * Set the audio URL.
     *
     * @param string $audioUrl
     * @return self
     */
    public function setAudioUrl(string $audioUrl): self
    {
        $this->audioUrl = $audioUrl;
        return $this;
    }

    /**
     * Get the audio content.
     *
     * @return string|null
     */
    public function getAudioContent(): ?string
    {
        return $this->audioContent;
    }

    /**
     * Set the audio content.
     *
     * @param string $audioContent
     * @return self
     */
    public function setAudioContent(string $audioContent): self
    {
        $this->audioContent = $audioContent;
        return $this;
    }

    /**
     * Get the audio duration.
     *
     * @return float|null
     */
    public function getDuration(): ?float
    {
        return $this->duration;
    }

    /**
     * Set the audio duration.
     *
     * @param float $duration
     * @return self
     */
    public function setDuration(float $duration): self
    {
        $this->duration = $duration;
        return $this;
    }

    /**
     * Convert to array representation.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'voice_id' => $this->voice->id,
            'text' => $this->text,
            'audio_url' => $this->audioUrl,
            'audio_format' => $this->audioFormat,
            'duration' => $this->duration,
            'status' => $this->status,
            'metadata' => $this->metadata,
        ];
    }
}
