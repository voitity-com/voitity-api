<?php

namespace App\Classes\VoiceService;

use App\Enums\SubscriptionUsageType;
use App\Events\Subscriptions\SubscriptionUsageRequested;
use App\Models\Voice;
use App\Models\VoiceProviderRequest;
use App\Models\VoiceSample;

class VoiceService
{
    /**
     * The voice instance this service operates on.
     */
    protected Voice $voice;

    /**
     * The voice client used for operations.
     */
    protected VoiceClient $voiceClient;

    /**
     * Create a new VoiceService instance.
     *
     * @param  Voice  $voice  The voice to operate on
     * @param  VoiceClient|null  $voiceClient  The voice client to use (optional, will resolve from manager if not provided)
     */
    public function __construct(Voice $voice, ?VoiceClient $voiceClient = null)
    {
        $this->voice = $voice;
        $this->voiceClient = $voiceClient ?: app(VoiceManager::class)->driver();
    }

    /**
     * Clone the voice using a voice sample.
     *
     * @param  VoiceSample  $voiceSample  The voice sample to use for cloning
     * @return VoiceClientClonedVoice The result of the cloning operation
     */
    public function cloneVoice(VoiceSample $voiceSample): VoiceClientClonedVoice
    {
        $voiceProviderRequest = VoiceProviderRequest::where('voice_id', $this->voice->id)
            ->where('voice_sample_id', $voiceSample->id)
            ->where('status', VoiceProviderRequest::STATUS_PENDING)
            ->first();

        $clonedVoice = $this->voiceClient->cloneVoice($this->voice, $voiceSample);

        if ($clonedVoice) {
            $voiceProviderRequest->status = VoiceProviderRequest::STATUS_COMPLETED;
            $voiceProviderRequest->source = $clonedVoice->source;
            $voiceProviderRequest->request_url = $clonedVoice->getRequestUrl();
            $voiceProviderRequest->response = json_encode($clonedVoice->getResponse());
            $voiceProviderRequest->processed_at = now();
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
     * @param  VoiceSample  $voiceSample  The voice sample to add
     * @return bool Indicates if the sample was added successfully
     */
    public function addSample(VoiceSample $voiceSample): bool
    {
        $voiceProviderRequest = VoiceProviderRequest::where('voice_id', $this->voice->id)
            ->where('voice_sample_id', $voiceSample->id)
            ->whereIn('status', [VoiceProviderRequest::STATUS_PENDING, VoiceProviderRequest::STATUS_FAILED])
            ->first();

        if ($voiceProviderRequest) {
            $addedSample = $this->voiceClient->addVoice($this->voice, $voiceSample);
            $voiceProviderRequest->status = VoiceProviderRequest::STATUS_COMPLETED;
            $voiceProviderRequest->request_url = $addedSample->requestUrl;
            $voiceProviderRequest->response = json_encode($addedSample->response);
            $voiceProviderRequest->processed_at = now();
            $voiceProviderRequest->save();
        }

        return true;
    }

    /**
     * Generate audio using the voice and provided text.
     *
     * @param  string  $text  The text to convert to audio
     * @return VoiceClientGeneratedAudio The generated audio result
     */
    public function generateAudio(string $text): VoiceClientGeneratedAudio
    {
        $generatedAudio = $this->voiceClient->generateAudio($this->voice, $text);

        if ($generatedAudio->isSuccessful() && $this->voice->user_id) {
            event(new SubscriptionUsageRequested(
                userId: $this->voice->user_id,
                usageType: SubscriptionUsageType::VoiceTtsCharacters,
                amounts: ['tts_characters' => $this->characterCount($text)],
                profileId: $this->voice->profile_id,
                sourceType: Voice::class,
                sourceId: (string) $this->voice->id,
                metadata: [
                    'provider' => $this->voice->source,
                    'voice_id' => $this->voice->id,
                    'audio_format' => $generatedAudio->audioFormat,
                    'status' => $generatedAudio->status,
                ]
            ));
        }

        return $generatedAudio;
    }

    /**
     * Get the voice instance.
     */
    public function getVoice(): Voice
    {
        return $this->voice;
    }

    /**
     * Get the voice client instance.
     */
    public function getVoiceClient(): VoiceClient
    {
        return $this->voiceClient;
    }

    /**
     * Set a different voice client.
     */
    public function setVoiceClient(VoiceClient $voiceClient): self
    {
        $this->voiceClient = $voiceClient;

        return $this;
    }

    /**
     * Create a new VoiceService instance for a different voice.
     */
    public function forVoice(Voice $voice): self
    {
        return new self($voice, $this->voiceClient);
    }

    private function characterCount(string $text): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($text);
        }

        return strlen($text);
    }
}
