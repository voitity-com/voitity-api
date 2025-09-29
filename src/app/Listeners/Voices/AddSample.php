<?php

namespace App\Listeners\Voices;

use App\Classes\VoiceService\VoiceService;
use App\Events\Voices\VoiceSampleAdded;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class AddSample implements ShouldQueue
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
     * Create the event listener.
     */
    public function __construct()
    {
        // VoiceService will automatically resolve VoiceClient from VoiceManager
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

        if (empty($voice->source_voice_id)) {
            Log::warning('Voice has no source_voice_id, it is going to be clonned, skipping AddSample listener', [
                'voice_id' => $voice->id,
                'voice_name' => $voice->name,
                'user_id' => $voice->user_id,
                'voice_sample_id' => $voiceSample->id,
            ]);
            return;
        }
        
        Log::info('AddSample listener triggered', [
            'voice_id' => $voice->id,
            'voice_name' => $voice->name,
            'user_id' => $voice->user_id,
            'voice_sample_id' => $voiceSample->id,
            'file' => $voiceSample->file,
            'duration' => $voiceSample->duration,
        ]);

        try {
            // Create VoiceService instance - it will resolve the VoiceClient from VoiceManager
            $voiceService = new VoiceService($voice);
            
            // Add the voice sample to the voice
            $success = $voiceService->addSample($voiceSample);
            
            Log::info('Voice sample addition successful', [
                'voice_id' => $voice->id,
                'voice_sample_id' => $voiceSample->id,
                'success' => $success,
                'file' => $voiceSample->file,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Voice sample addition failed during processing', [
                'voice_id' => $voice->id,
                'voice_sample_id' => $voiceSample->id,
                'file' => $voiceSample->file,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Re-throw to trigger the failed method
            throw $e;
        }
        
        Log::info('AddSample processing completed', [
            'voice_id' => $voice->id,
            'voice_sample_id' => $voiceSample->id,
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
        
        Log::error('AddSample listener failed', [
            'voice_id' => $voice->id,
            'voice_sample_id' => $voiceSample->id,
            'user_id' => $voice->user_id,
            'file' => $voiceSample->file,
            'error' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'attempts' => $this->attempts(),
        ]);

        // TODO: Implement comprehensive failure handling:
        // - Update VoiceProviderRequest status to failed
        // - Mark voice sample processing as failed
        // - Send notification to user about the failure
        // - Store detailed error information for debugging
        // - Consider retry logic based on error type
        
        // Example implementation could be:
        // $voiceService = new VoiceService($voice);
        // $voiceService->markSampleAdditionAsFailed($voiceSample, $exception->getMessage());
    }
}
