<?php

namespace App\Classes\VoiceService;

use App\Models\Voice;
use App\Models\VoiceProviderRequest;
use App\Models\VoiceSample;

class VoiceService
{
    /**
     * The voice instance this service operates on.
     *
     * @var Voice
     */
    protected Voice $voice;

    /**
     * The voice client used for operations.
     *
     * @var VoiceClient
     */
    protected VoiceClient $voiceClient;

    /**
     * Create a new VoiceService instance.
     *
     * @param Voice $voice The voice to operate on
     * @param VoiceClient|null $voiceClient The voice client to use (optional, will resolve from manager if not provided)
     */
    public function __construct(Voice $voice, ?VoiceClient $voiceClient = null)
    {
        $this->voice = $voice;
        $this->voiceClient = $voiceClient ?: app(VoiceManager::class)->driver();
    }

    /**
     * Clone the voice using a voice sample.
     *
     * @param VoiceSample $voiceSample The voice sample to use for cloning
     * @return VoiceClientClonedVoice The result of the cloning operation
     */
    public function cloneVoice(VoiceSample $voiceSample): VoiceClientClonedVoice
    {
        $voiceProviderRequest = VoiceProviderRequest::where('voice_id', $this->voice->id)
            ->where('voice_sample_id', $voiceSample->id)
            ->where('status', VoiceProviderRequest::STATUS_PENDING)
            ->first();
            
        $clonedVoice = $this->voiceClient->cloneVoice($this->voice, $voiceSample);

        // Set VoiceProviderRequest status to completed VoiceProviderRequest::STATUS_COMPLETED if it succesful
        if ($clonedVoice) {
            $voiceProviderRequest->status = VoiceProviderRequest::STATUS_COMPLETED;
            $voiceProviderRequest->save();

            $this->voice->source = $clonedVoice->source;
            $this->voice->source_voice_id = $clonedVoice->getProviderVoiceId();
            $this->voice->save();
        }

        return $clonedVoice;
    }

    /**
     * Add a voice sample to the voice.
     *
     * @param VoiceSample $voiceSample The voice sample to add
     * @return bool Indicates if the sample was added successfully
     */
    public function addSample(VoiceSample $voiceSample): bool
    {
        return $this->voiceClient->addVoice($this->voice, $voiceSample);
    }

    /**
     * Generate audio using the voice and provided text.
     *
     * @param string $text The text to convert to audio
     * @return VoiceClientGeneratedAudio The generated audio result
     */
    public function generateAudio(string $text): VoiceClientGeneratedAudio
    {
        return $this->voiceClient->generateAudio($this->voice, $text);
    }

    /**
     * Get the voice instance.
     *
     * @return Voice
     */
    public function getVoice(): Voice
    {
        return $this->voice;
    }

    /**
     * Get the voice client instance.
     *
     * @return VoiceClient
     */
    public function getVoiceClient(): VoiceClient
    {
        return $this->voiceClient;
    }

    /**
     * Set a different voice client.
     *
     * @param VoiceClient $voiceClient
     * @return self
     */
    public function setVoiceClient(VoiceClient $voiceClient): self
    {
        $this->voiceClient = $voiceClient;
        return $this;
    }

    /**
     * Create a new VoiceService instance for a different voice.
     *
     * @param Voice $voice
     * @return self
     */
    public function forVoice(Voice $voice): self
    {
        return new self($voice, $this->voiceClient);
    }
}
