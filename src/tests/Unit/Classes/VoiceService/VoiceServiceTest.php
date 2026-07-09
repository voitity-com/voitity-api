<?php

namespace Tests\Unit\Classes\VoiceService;

use App\Classes\VoiceService\VoiceClient;
use App\Classes\VoiceService\VoiceClientAddedSample;
use App\Classes\VoiceService\VoiceClientClonedVoice;
use App\Classes\VoiceService\VoiceClientGeneratedAudio;
use App\Classes\VoiceService\VoiceService;
use App\Models\User;
use App\Models\Voice;
use App\Models\VoiceProviderRequest;
use App\Models\VoiceSample;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceServiceTest extends TestCase
{
    #[Test]
    public function it_stores_provider_voice_id_on_clone_request_for_auditability(): void
    {
        $user = User::factory()->create();
        $voice = Voice::factory()->create([
            'user_id' => $user->id,
            'source' => null,
            'source_voice_id' => null,
        ]);
        $sample = VoiceSample::factory()->create(['voice_id' => $voice->id]);
        $request = VoiceProviderRequest::factory()->pending()->create([
            'voice_id' => $voice->id,
            'voice_sample_id' => $sample->id,
            'source' => '',
            'source_voice_id' => null,
            'request_url' => '',
        ]);
        $client = new class implements VoiceClient
        {
            public function cloneVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientClonedVoice
            {
                return new VoiceClientClonedVoice(
                    source: 'elevenlabs',
                    providerVoiceId: 'provider-voice-123',
                    status: 'completed',
                    response: ['voice_id' => 'provider-voice-123'],
                    requestUrl: 'https://api.elevenlabs.io/v1/voices/add'
                );
            }

            public function addVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientAddedSample
            {
                return new VoiceClientAddedSample('elevenlabs');
            }

            public function generateAudio(Voice $voice, string $text): VoiceClientGeneratedAudio
            {
                return new VoiceClientGeneratedAudio($voice, $text);
            }
        };

        (new VoiceService($voice, $client))->cloneVoice($sample);

        $request->refresh();
        $voice->refresh();

        $this->assertSame(VoiceProviderRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame('elevenlabs', $request->source);
        $this->assertSame('provider-voice-123', $request->source_voice_id);
        $this->assertSame('provider-voice-123', $voice->source_voice_id);
        $this->assertSame('elevenlabs', $voice->source);
    }
}
