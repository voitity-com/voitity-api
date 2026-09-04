<?php

namespace App\Classes\VoiceService\ElevenLabs;

use App\Classes\VoiceService\DeletesProviderVoices;
use App\Classes\VoiceService\VoiceClient;
use App\Classes\VoiceService\VoiceClientAddedSample;
use App\Classes\VoiceService\VoiceClientClonedVoice;
use App\Classes\VoiceService\VoiceClientGeneratedAudio;
use App\Exceptions\Voices\ElevenLabsVoiceClientCouldNotAddSample;
use App\Exceptions\Voices\ElevenLabsVoiceClientCouldNotAuthenticate;
use App\Exceptions\Voices\ElevenLabsVoiceClientCouldNotCloneVoice;
use App\Models\Voice;
use App\Models\VoiceSample;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ElevenLabsVoiceClient implements DeletesProviderVoices, VoiceClient
{
    /**
     * ElevenLabs API base URL.
     *
     * @var string
     */
    protected $baseUrl;

    /**
     * ElevenLabs API key.
     *
     * @var string
     */
    protected $apiKey;

    /**
     * Default voice settings.
     *
     * @var array
     */
    protected $defaultVoiceSettings;

    /**
     * Create a new ElevenLabsVoiceClient instance.
     */
    public function __construct()
    {
        $this->baseUrl = config('voice.drivers.elevenlabs.base_url');
        $this->apiKey = config('voice.drivers.elevenlabs.api_key');
        $this->defaultVoiceSettings = config('voice.drivers.elevenlabs.default_voice_settings');

        if (! $this->apiKey) {
            throw new ElevenLabsVoiceClientCouldNotAuthenticate('ElevenLabs API key is not configured');
        }
    }

    /**
     * Clone a voice using a voice sample.
     *
     * @param  Voice  $voice  The voice to clone
     * @param  VoiceSample  $voiceSample  The voice sample to use for cloning
     * @return VoiceClientClonedVoice The result of the cloning operation
     */
    public function cloneVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientClonedVoice
    {
        try {
            Log::info('ElevenLabs: Starting voice cloning', [
                'voice_id' => $voice->id,
                'voice_sample_id' => $voiceSample->id,
            ]);

            // Get the audio file from storage
            if (! Storage::exists($voiceSample->file)) {
                throw new \Exception("Voice sample file not found: {$voiceSample->file}");
            }

            $audioContent = Storage::get($voiceSample->file);

            $requestUrl = "{$this->baseUrl}/v1/voices/add";
            $response = Http::withHeaders([
                'xi-api-key' => $this->apiKey,
            ])->attach(
                'files',
                $audioContent,
                basename($voiceSample->file)
            )->post($requestUrl, [
                'name' => $voice->name,
                'description' => $voice->description ?? "Cloned voice for {$voice->name}",
                'remove_background_noise' => true,
                'labels' => json_encode([
                    'voice_id' => (string) $voice->id,
                    'source' => 'voitity_clone',
                ]),
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                $providerVoiceId = $responseData['voice_id'] ?? null;

                Log::info('ElevenLabs: Voice cloning successful', [
                    'voice_id' => $voice->id,
                    'provider_voice_id' => $providerVoiceId,
                ]);

                return new VoiceClientClonedVoice(
                    'elevenlabs',
                    $providerVoiceId,
                    'completed',
                    $responseData,
                    $requestUrl
                );
            } else {
                $json = $response->json();
                $error = isset($json['detail']['message']) ? $json['detail']['message'] : 'Unknown error';

                Log::error('ElevenLabs: Voice cloning failed', [
                    'voice_id' => $voice->id,
                    'error' => $error,
                    'response' => $response->body(),
                ]);

                throw new ElevenLabsVoiceClientCouldNotCloneVoice($error);
            }
        } catch (\Exception $e) {
            Log::error('ElevenLabs: Voice cloning exception', [
                'voice_id' => $voice->id,
                'error' => $e->getMessage(),
            ]);

            throw new ElevenLabsVoiceClientCouldNotCloneVoice('ElevenLabs: Voice cloning failed: '.$e->getMessage());
        }
    }

    public function deleteProviderVoice(Voice $voice, string $providerVoiceId): bool
    {
        $encodedProviderVoiceId = rawurlencode($providerVoiceId);
        $response = Http::withHeaders([
            'xi-api-key' => $this->apiKey,
        ])->delete("{$this->baseUrl}/v1/voices/{$encodedProviderVoiceId}");

        if (! $response->successful()) {
            Log::error('ElevenLabs: Failed to delete replaced voice.', [
                'provider_voice_id' => $providerVoiceId,
                'response' => $response->body(),
                'voice_id' => $voice->id,
            ]);

            throw new ElevenLabsVoiceClientCouldNotCloneVoice(
                'ElevenLabs: Replaced voice cleanup failed: '.$response->body()
            );
        }

        Log::info('ElevenLabs: Replaced voice deleted.', [
            'provider_voice_id' => $providerVoiceId,
            'voice_id' => $voice->id,
        ]);

        return true;
    }

    /**
     * Add a voice sample to a voice.
     *
     * @param  Voice  $voice  The voice to add the sample to
     * @param  VoiceSample  $voiceSample  The voice sample to add
     * @return VoiceClientAddedSample The result of the sample addition operation
     */
    public function addVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientAddedSample
    {
        try {
            Log::info('ElevenLabs: Adding voice sample', [
                'voice_id' => $voice->id,
                'voice_sample_id' => $voiceSample->id,
            ]);

            // For ElevenLabs, we need the source voice ID to add samples
            if (! $voice->source_voice_id) {
                Log::error('ElevenLabs: No source voice ID found for voice', [
                    'voice_id' => $voice->id,
                ]);
                throw new ElevenLabsVoiceClientCouldNotAddSample('ElevenLabs: Voice must have a source_voice_id to add samples');
            }

            // Get the audio file from storage
            if (! Storage::exists($voiceSample->file)) {
                throw new \Exception("Voice sample file not found: {$voiceSample->file}");
            }

            $audioContent = Storage::get($voiceSample->file);

            $requestUrl = "{$this->baseUrl}/v1/voices/{$voice->source_voice_id}/edit";

            // For ElevenLabs, use the correct samples endpoint with proper form data
            $response = Http::withHeaders([
                'xi-api-key' => $this->apiKey,
            ])->attach(
                'files',
                $audioContent,
                basename($voiceSample->file)
            )->post($requestUrl, [
                'name' => $voice->name,
                'remove_background_noise' => true,
            ]);

            if ($response->successful()) {
                Log::info('ElevenLabs: Voice sample added successfully', [
                    'voice_id' => $voice->id,
                    'voice_sample_id' => $voiceSample->id,
                ]);

                return new VoiceClientAddedSample(
                    'elevenlabs',
                    'completed',
                    $response->json() ?? [],
                    $requestUrl
                );
            } else {
                Log::error('ElevenLabs: Failed to add voice sample', [
                    'voice_id' => $voice->id,
                    'error' => $response->body(),
                ]);
                throw new ElevenLabsVoiceClientCouldNotAddSample($response->body());
            }

        } catch (\Exception $e) {
            Log::error('ElevenLabs: Exception adding voice sample', [
                'voice_id' => $voice->id,
                'error' => $e->getMessage(),
            ]);

            throw new ElevenLabsVoiceClientCouldNotAddSample('ElevenLabs: Failed to add voice sample: '.$e->getMessage());
        }
    }

    /**
     * Generate audio using a voice and text.
     *
     * @param  Voice  $voice  The voice to use for generation
     * @param  string  $text  The text to convert to audio
     * @return VoiceClientGeneratedAudio The generated audio result
     */
    public function generateAudio(Voice $voice, string $text): VoiceClientGeneratedAudio
    {
        try {
            Log::info('ElevenLabs: Starting audio generation', [
                'voice_id' => $voice->id,
                'text_length' => strlen($text),
            ]);

            // For ElevenLabs, we need the provider voice ID
            if (! $voice->source_voice_id) {
                throw new \Exception("No provider voice ID found for voice {$voice->id}");
            }

            $response = Http::withHeaders([
                'xi-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/v1/text-to-speech/{$voice->source_voice_id}", [
                'text' => $text,
                'model_id' => config('voice.drivers.elevenlabs.model_id'),
                'language_code' => $voice->language_code,
                'voice_settings' => $this->defaultVoiceSettings,
            ]);

            if ($response->successful()) {
                $audioContent = $response->body();
                $audioUrl = $this->storeGeneratedAudio($voice, $audioContent);

                Log::info('ElevenLabs: Audio generation successful', [
                    'voice_id' => $voice->id,
                    'audio_size' => strlen($audioContent),
                ]);

                return new VoiceClientGeneratedAudio(
                    $voice,
                    $text,
                    $audioUrl,
                    base64_encode($audioContent),
                    'mp3',
                    null, // Duration would need to be calculated separately
                    'completed',
                    ['provider' => 'elevenlabs']
                );
            } else {
                Log::error('ElevenLabs: Audio generation failed', [
                    'voice_id' => $voice->id,
                    'response' => $response->body(),
                ]);

                return new VoiceClientGeneratedAudio(
                    $voice,
                    $text,
                    null,
                    null,
                    'mp3',
                    null,
                    'failed',
                    ['error' => $response->body()]
                );
            }
        } catch (\Exception $e) {
            Log::error('ElevenLabs: Audio generation exception', [
                'voice_id' => $voice->id,
                'error' => $e->getMessage(),
            ]);

            return new VoiceClientGeneratedAudio(
                $voice,
                $text,
                null,
                null,
                'mp3',
                null,
                'failed',
                ['error' => $e->getMessage()]
            );
        }
    }

    /**
     * Store generated audio file and return URL.
     */
    protected function storeGeneratedAudio(Voice $voice, string $audioContent): string
    {
        try {
            $diskName = config('voice.generated_audio.disk', 'public');
            $folder = trim((string) config('voice.generated_audio.folder', 'generated'), '/');
            $visibility = config('voice.generated_audio.visibility', 'public');
            $filename = ($folder ? "{$folder}/" : '').$voice->id.'/'.uniqid().'.mp3';
            $disk = Storage::disk($diskName);

            $stored = $disk->put($filename, $audioContent, [
                'ContentType' => 'audio/mpeg',
                'visibility' => $visibility,
            ]);

            if (! $stored) {
                throw new \RuntimeException("Unable to store generated audio at {$filename}.");
            }

            $audioUrl = $disk->url($filename);

            if (trim($audioUrl) === '') {
                throw new \RuntimeException("Generated audio URL is empty for {$filename}.");
            }

            return $audioUrl;
        } catch (\Throwable $e) {
            Log::error('Failed to store generated audio', [
                'voice_id' => $voice->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
