<?php

namespace App\Listeners\Voices;

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
        //
    }

    /**
     * Handle the event.
     *
     * @param VoiceSampleAdded $event
     * @return void
     */
    public function handle(VoiceSampleAdded $event): void
    {
        $voiceSample = $event->voiceSample;
        
        Log::info('AddSample listener triggered', [
            'voice_sample_id' => $voiceSample->id,
            'voice_id' => $voiceSample->voice_id,
            'file' => $voiceSample->file,
            'duration' => $voiceSample->duration,
        ]);

        // TODO: Implement voice sample processing logic here
        // This could involve:
        // 1. Processing the audio file for quality analysis
        // 2. Extracting additional metadata
        // 3. Creating voice provider requests for external services
        // 4. Updating voice training data
        // 5. Notifying the voice cloning service of new samples
        
        // Example placeholder logic:
        // $this->processAudioFile($voiceSample);
        // $this->updateVoiceTrainingData($voiceSample);
        // $this->notifyExternalServices($voiceSample);
        
        Log::info('AddSample processing completed', [
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
        Log::error('AddSample listener failed', [
            'voice_sample_id' => $event->voiceSample->id,
            'voice_id' => $event->voiceSample->voice_id,
            'error' => $exception->getMessage(),
        ]);

        // TODO: Handle failure logic
        // - Mark voice sample as failed to process
        // - Send notification to user
        // - Create error logs
        // - Possibly retry with different parameters
    }
}
