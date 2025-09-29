<?php

namespace App\Classes\VoiceService;

use App\Models\Voice;
use App\Models\VoiceSample;

interface VoiceClient
{
    /**
     * Clone a voice using a voice sample.
     *
     * @param Voice $voice The voice to clone
     * @param VoiceSample $voiceSample The voice sample to use for cloning
     * @return VoiceClientClonedVoice The result of the cloning operation
     */
    public function cloneVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientClonedVoice;

    /**
     * Add a voice sample to a voice.
     *
     * @param Voice $voice The voice to add the sample to
     * @param VoiceSample $voiceSample The voice sample to add
     * @return VoiceClientAddedSample The result of the sample addition operation
     */
    public function addVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientAddedSample;

    /**
     * Generate audio using a voice and text.
     *
     * @param Voice $voice The voice to use for generation
     * @param string $text The text to convert to audio
     * @return VoiceClientGeneratedAudio The generated audio result
     */
    public function generateAudio(Voice $voice, string $text): VoiceClientGeneratedAudio;
}
