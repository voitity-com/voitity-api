<?php

namespace App\Events\Voices;

use App\Models\Voice;
use App\Models\VoiceSample;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class VoiceSampleAdded
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The voice that was created.
     *
     * @var Voice
     */
    public $voice;

    /**
     * Undocumented variable
     *
     * @var VoiceSample
     */
    public $voiceSample;

    /**
     * Create a new event instance.
     *
     * @param Voice $voice
     */
    public function __construct(Voice $voice, VoiceSample $voiceSample)
    {
        $this->voice = $voice;
        $this->voiceSample = $voiceSample;
        
        Log::info('VoiceSampleAdded event instantiated', [
            'voice_id' => $voice->id,
            'voice_name' => $voice->name,
            'user_id' => $voice->user_id,
            'voice_sample_id' => $voiceSample->id,
        ]);
    }
}
