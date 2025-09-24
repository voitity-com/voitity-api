<?php

namespace App\Classes\VoiceService\ElevenLabs;

use App\Classes\VoiceService\VoiceClient;
use App\Classes\VoiceService\VoiceClientClonedVoice;
use App\Classes\VoiceService\VoiceClientGeneratedAudio;
use App\Exceptions\Voices\ElevenLabsVoiceClientCouldNotAddSample;
use App\Exceptions\Voices\ElevenLabsVoiceClientCouldNotAuthenticate;
use App\Exceptions\Voices\ElevenLabsVoiceClientCouldNotCloneVoice;
use App\Models\Voice;
use App\Models\VoiceSample;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ElevenLabsVoiceClient implements VoiceClient
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

        if (!$this->apiKey) {
            throw new ElevenLabsVoiceClientCouldNotAuthenticate('ElevenLabs API key is not configured');
        }
    }

    /**
     * Clone a voice using a voice sample.
     *
     * @param Voice $voice The voice to clone
     * @param VoiceSample $voiceSample The voice sample to use for cloning
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
            $audioPath = Storage::path($voiceSample->file);
            
            if (!file_exists($audioPath)) {
                throw new \Exception("Voice sample file not found: {$voiceSample->file}");
            }

            $response = Http::withHeaders([
                'xi-api-key' => $this->apiKey,
            ])->attach(
                'files',
                file_get_contents($audioPath),
                basename($voiceSample->file)
            )->post("{$this->baseUrl}/v1/voices/add", [
                'name' => $voice->name,
                'description' => $voice->description ?? "Cloned voice for {$voice->name}",
                'remove_background_noise' => true,
                'labels' => json_encode([
                    'voice_id' => $voice->id,
                    'source' => 'voitity_clone'
                ])
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                $providerVoiceId = $responseData['voice_id'] ?? null;

                Log::info('ElevenLabs: Voice cloning successful', [
                    'voice_id' => $voice->id,
                    'provider_voice_id' => $providerVoiceId,
                ]);

                return new VoiceClientClonedVoice(
                    $voice,
                    $voiceSample,
                    $providerVoiceId,
                    'completed',
                    $responseData
                );
            } else {
                $error = $response->json()['detail']['message'] ?? 'Unknown error';
                
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

            throw new ElevenLabsVoiceClientCouldNotCloneVoice('ElevenLabs: Voice cloning failed: ' . $e->getMessage());
        }
    }

    /**
     * Add a voice sample to a voice.
     *
     * @param Voice $voice The voice to add the sample to
     * @param VoiceSample $voiceSample The voice sample to add
     * @return bool Indicate if the sample was added successfully
     */
    public function addVoice(Voice $voice, VoiceSample $voiceSample): bool
    {
        try {
            Log::info('ElevenLabs: Adding voice sample', [
                'voice_id' => $voice->id,
                'voice_sample_id' => $voiceSample->id,
            ]);

            // For ElevenLabs, we need the provider voice ID to add samples
            if (!$voice->provider_voice_id) {
                Log::warning('ElevenLabs: No provider voice ID found for voice', [
                    'voice_id' => $voice->id,
                ]);
                return false;
            }

            // Get the audio file from storage
            $audioPath = Storage::path($voiceSample->file);
            
            if (!file_exists($audioPath)) {
                throw new \Exception("Voice sample file not found: {$voiceSample->file}");
            }

            $response = Http::withHeaders([
                'xi-api-key' => $this->apiKey,
            ])->attach(
                'files',
                file_get_contents($audioPath),
                basename($voiceSample->file)
            )->post("{$this->baseUrl}/v1/voices/{$voice->provider_voice_id}/samples");

            if ($response->successful()) {
                Log::info('ElevenLabs: Voice sample added successfully', [
                    'voice_id' => $voice->id,
                    'voice_sample_id' => $voiceSample->id,
                ]);
                return true;
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

            throw new ElevenLabsVoiceClientCouldNotAddSample('ElevenLabs: Voice cloning failed: ' . $e->getMessage());
        }
    }

    /**
     * Generate audio using a voice and text.
     *
     * @param Voice $voice The voice to use for generation
     * @param string $text The text to convert to audio
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
            if (!$voice->provider_voice_id) {
                throw new \Exception("No provider voice ID found for voice {$voice->id}");
            }

            $response = Http::withHeaders([
                'xi-api-key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ])->post("{$this->baseUrl}/v1/text-to-speech/{$voice->provider_voice_id}", [
                'text' => $text,
                'model_id' => config('voice.drivers.elevenlabs.model_id', 'eleven_monolingual_v1'),
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
                $error = $response->json()['detail']['message'] ?? 'Unknown error';
                
                Log::error('ElevenLabs: Audio generation failed', [
                    'voice_id' => $voice->id,
                    'error' => $error,
                ]);

                return new VoiceClientGeneratedAudio(
                    $voice,
                    $text,
                    null,
                    null,
                    'mp3',
                    null,
                    'failed',
                    ['error' => $error, 'response' => $response->json()]
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
     *
     * @param Voice $voice
     * @param string $audioContent
     * @return string|null
     */
    protected function storeGeneratedAudio(Voice $voice, string $audioContent): ?string
    {
        try {
            $filename = 'generated/' . $voice->id . '/' . uniqid() . '.mp3';
            Storage::put($filename, $audioContent);
            return Storage::url($filename);
        } catch (\Exception $e) {
            Log::error('Failed to store generated audio', [
                'voice_id' => $voice->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
