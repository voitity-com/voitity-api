<?php

namespace App\Listeners\Voices;

use App\Classes\VoiceService\VoiceService;
use App\Classes\VoiceService\VoiceManager;
use App\Events\Voices\VoiceSampleAdded;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CloneVoice implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The maximum number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * The VoiceManager instance.
     *
     * @var VoiceManager
     */
    protected VoiceManager $voiceManager;

    /**
     * Create the event listener.
     */
    public function __construct(VoiceManager $voiceManager)
    {
        $this->voiceManager = $voiceManager;
    }

    /**
     * Handle the event.
     *
     * @param VoiceSampleAdded $event
     * @return void
     */
    public function handle(VoiceSampleAdded $event): void
    {
        $voice = $event->voice;
        $voiceSample = $event->voiceSample;

        if ($voice->source_voice_id) {
            Log::info('Voice already cloned, skipping CloneVoice listener', [
                'voice_id' => $voice->id,
                'voice_name' => $voice->name,
                'user_id' => $voice->user_id,
                'voice_sample_id' => $voiceSample->id,
                'file' => $voiceSample->file,
                'duration' => $voiceSample->duration,
            ]);
            return;
        }
        
        Log::info('CloneVoice listener triggered', [
            'voice_id' => $voice->id,
            'voice_name' => $voice->name,
            'user_id' => $voice->user_id,
            'voice_sample_id' => $voiceSample->id, 
        ]);

        try {
            // Create VoiceService instance using VoiceManager
            $voiceClient = $this->voiceManager->driver();
            $voiceService = new VoiceService($voice, $voiceClient);
            
            // Clone the voice using the voice sample
            $clonedVoice = $voiceService->cloneVoice($voiceSample);
            
            Log::info('Voice cloning successful', [
                'voice_id' => $voice->id,
                'voice_sample_id' => $voiceSample->id,
                'source' => $clonedVoice->source,
                'provider_voice_id' => $clonedVoice->getProviderVoiceId(),
                'status' => $clonedVoice->status,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Voice cloning failed during processing', [
                'voice_id' => $voice->id,
                'voice_sample_id' => $voiceSample->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Re-throw to trigger the failed method
            throw $e;
        }
        
        Log::info('CloneVoice processing completed', [
            'voice_id' => $voice->id,
        ]);
    }

    /**
     * Handle a job failure.
     *
     * @param VoiceSampleAdded $event
     * @param \Throwable $exception
     * @return void
     */
    public function failed(VoiceSampleAdded $event, \Throwable $exception): void
    {
        $voice = $event->voice;
        $voiceSample = $event->voiceSample;
        
        Log::error('CloneVoice listener failed', [
            'voice_id' => $voice->id,
            'voice_sample_id' => $voiceSample->id,
            'user_id' => $voice->user_id,
            'error' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'attempts' => $this->attempts(),
        ]);
    }
}
