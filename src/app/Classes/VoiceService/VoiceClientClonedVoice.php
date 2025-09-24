<?php

namespace App\Classes\VoiceService;

use App\Models\Voice;
use App\Models\VoiceSample;

class VoiceClientClonedVoice
{
    /**
     * The voice that was cloned.
     *
     * @var Voice
     */
    public $voice;

    /**
     * The voice sample used for cloning.
     *
     * @var VoiceSample
     */
    public $voiceSample;

    /**
     * The external provider voice ID.
     *
     * @var string|null
     */
    public $providerVoiceId;

    /**
     * The status of the cloning operation.
     *
     * @var string
     */
    public $status;

    /**
     * Additional metadata from the cloning operation.
     *
     * @var array
     */
    public $metadata;

    /**
     * Create a new VoiceClientClonedVoice instance.
     *
     * @param Voice $voice
     * @param VoiceSample $voiceSample
     * @param string|null $providerVoiceId
     * @param string $status
     * @param array $metadata
     */
    public function __construct(
        Voice $voice,
        VoiceSample $voiceSample,
        ?string $providerVoiceId = null,
        string $status = 'pending',
        array $metadata = []
    ) {
        $this->voice = $voice;
        $this->voiceSample = $voiceSample;
        $this->providerVoiceId = $providerVoiceId;
        $this->status = $status;
        $this->metadata = $metadata;
    }

    /**
     * Check if the cloning operation was successful.
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'completed' || $this->status === 'success';
    }

    /**
     * Check if the cloning operation failed.
     *
     * @return bool
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed' || $this->status === 'error';
    }

    /**
     * Check if the cloning operation is pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === 'pending' || $this->status === 'processing';
    }

    /**
     * Get the provider voice ID.
     *
     * @return string|null
     */
    public function getProviderVoiceId(): ?string
    {
        return $this->providerVoiceId;
    }

    /**
     * Set the provider voice ID.
     *
     * @param string $providerVoiceId
     * @return self
     */
    public function setProviderVoiceId(string $providerVoiceId): self
    {
        $this->providerVoiceId = $providerVoiceId;
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
            'voice_sample_id' => $this->voiceSample->id,
            'provider_voice_id' => $this->providerVoiceId,
            'status' => $this->status,
            'metadata' => $this->metadata,
        ];
    }
}
