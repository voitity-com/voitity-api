<?php

namespace App\Classes\VoiceService;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Enums\SubscriptionUsageType;
use App\Models\Voice;
use App\Models\VoiceProviderRequest;
use App\Models\VoiceSample;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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
            $voiceProviderRequest->source_voice_id = $clonedVoice->getProviderVoiceId();
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
            $voiceProviderRequest->source = $addedSample->source;
            $voiceProviderRequest->source_voice_id = $this->voice->source_voice_id;
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
        $usageKey = null;
        $recorder = app(SubscriptionUsageRecorder::class);

        if ($this->voice->user_id) {
            $usageKey = 'voice-tts:'.$this->voice->id.':'.Str::uuid();
            $recorder->reserve(
                userId: $this->voice->user_id,
                usageType: SubscriptionUsageType::VoiceTtsCharacters,
                amounts: ['tts_characters' => $this->characterCount($text)],
                idempotencyKey: $usageKey,
                profileId: $this->voice->profile_id,
                sourceType: Voice::class,
                sourceId: (string) $this->voice->id,
                metadata: [
                    'provider' => $this->voice->source,
                    'voice_id' => $this->voice->id,
                ],
            );
        }

        try {
            $generatedAudio = $this->voiceClient->generateAudio($this->voice, $text);
        } catch (\Throwable $exception) {
            if ($usageKey) {
                $recorder->release($usageKey);
            }

            Log::warning('TTS provider call failed after reserving usage.', [
                'error' => $exception->getMessage(),
                'usage_key' => $usageKey,
                'voice_id' => $this->voice->id,
            ]);

            throw $exception;
        }

        if ($usageKey) {
            if ($generatedAudio->isSuccessful()) {
                $recorder->finalize($usageKey, [
                    'provider' => $this->voice->source,
                    'voice_id' => $this->voice->id,
                    'audio_format' => $generatedAudio->audioFormat,
                    'status' => $generatedAudio->status,
                ]);
            } else {
                $recorder->release($usageKey);
            }
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
