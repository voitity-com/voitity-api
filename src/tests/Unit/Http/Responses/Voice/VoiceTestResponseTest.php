<?php

namespace Tests\Unit\Http\Responses\Voice;

use App\Classes\VoiceService\VoiceClientGeneratedAudio;
use App\Http\Responses\Voice\VoiceTestResponse;
use App\Models\Voice;
use Tests\TestCase;

class VoiceTestResponseTest extends TestCase
{
    public function test_to_array_returns_generated_audio_payload(): void
    {
        $voice = new Voice();
        $voice->setRawAttributes([
            'id' => 10,
            'profile_id' => 20,
        ], true);

        $generatedAudio = new VoiceClientGeneratedAudio(
            $voice,
            'Hola mundo',
            'http://localhost/storage/generated/10/audio.mp3',
            'base64-audio',
            'mp3',
            2.4,
            'completed',
            ['provider' => 'elevenlabs']
        );

        $payload = (new VoiceTestResponse($generatedAudio))->toArray();

        $this->assertSame(10, $payload['voice_id']);
        $this->assertSame(20, $payload['profile_id']);
        $this->assertSame('Hola mundo', $payload['text']);
        $this->assertSame('http://localhost/storage/generated/10/audio.mp3', $payload['audio_url']);
        $this->assertSame('base64-audio', $payload['audio_content']);
        $this->assertSame('mp3', $payload['audio_format']);
        $this->assertSame(2.4, $payload['duration']);
        $this->assertSame('completed', $payload['status']);
        $this->assertSame(['provider' => 'elevenlabs'], $payload['metadata']);
    }
}
