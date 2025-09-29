<?php

namespace App\Listeners\Voices;

use App\Classes\VoiceService\VoiceService;
use App\Classes\VoiceService\VoiceManager;
use App\Events\Voices\VoiceSampleAdded;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Alternative implementation showing different DI approaches
 */
class CloneVoiceAlternatives implements ShouldQueue
{
    use InteractsWithQueue;

    public $tries = 3;
    public $timeout = 120;

    // APPROACH 1: Constructor Injection with VoiceManager (Clean and proper)
    protected VoiceManager $voiceManager;
    
    public function __construct(VoiceManager $voiceManager)
    {
        $this->voiceManager = $voiceManager;
    }

    public function handle(VoiceSampleAdded $event): void
    {
        $voice = $event->voice;
        $voiceSample = $event->voiceSample;
        
        // Create VoiceService with the manager - clean and straightforward
        $voiceClient = $this->voiceManager->driver();
        $voiceService = new VoiceService($voice, $voiceClient);
        
        $clonedVoice = $voiceService->cloneVoice($voiceSample);
        
        Log::info('Voice cloned successfully', [
            'voice_id' => $voice->id,
            'source' => $clonedVoice->source,
        ]);
    }
}

/**
 * APPROACH 2: Constructor Injection with VoiceManager
 */
class CloneVoiceWithManager implements ShouldQueue
{
    use InteractsWithQueue;

    public $tries = 3;
    public $timeout = 120;
    
    protected VoiceManager $voiceManager;
    
    public function __construct(VoiceManager $voiceManager)
    {
        $this->voiceManager = $voiceManager;
    }

    public function handle(VoiceSampleAdded $event): void
    {
        $voice = $event->voice;
        $voiceSample = $event->voiceSample;
        
        // Create VoiceService manually with the manager
        $voiceClient = $this->voiceManager->driver();
        $voiceService = new VoiceService($voice, $voiceClient);
        
        $clonedVoice = $voiceService->cloneVoice($voiceSample);
        
        Log::info('Voice cloned successfully', [
            'voice_id' => $voice->id,
            'source' => $clonedVoice->source,
        ]);
    }
}

/**
 * APPROACH 3: Method Parameter Injection (Like Controllers)
 * NOTE: This doesn't work for queued listeners because Laravel serializes them
 */
class CloneVoiceMethodInjection implements ShouldQueue
{
    use InteractsWithQueue;

    public $tries = 3;
    public $timeout = 120;

    // This WON'T work for queued listeners!
    // Queued jobs are serialized and can't have complex dependencies in method signatures
    public function handle(VoiceSampleAdded $event, VoiceService $voiceServiceFactory): void
    {
        $voice = $event->voice;
        $voiceSample = $event->voiceSample;
        
        $voiceService = $voiceServiceFactory($voice);
        $clonedVoice = $voiceService->cloneVoice($voiceSample);
        
        Log::info('Voice cloned successfully', [
            'voice_id' => $voice->id,
            'source' => $clonedVoice->source,
        ]);
    }
}

/**
 * APPROACH 4: Using app() helper (Current original approach)
 */
class CloneVoiceWithHelper implements ShouldQueue
{
    use InteractsWithQueue;

    public $tries = 3;
    public $timeout = 120;

    public function handle(VoiceSampleAdded $event): void
    {
        $voice = $event->voice;
        $voiceSample = $event->voiceSample;
        
        // Direct service container access
        $voiceServiceFactory = app(VoiceService::class);
        $voiceService = $voiceServiceFactory($voice);
        
        $clonedVoice = $voiceService->cloneVoice($voiceSample);
        
        Log::info('Voice cloned successfully', [
            'voice_id' => $voice->id,
            'source' => $clonedVoice->source,
        ]);
    }
}
