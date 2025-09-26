<?php

namespace Tests\Unit\Classes\VoiceService\ElevenLabs;

use App\Classes\VoiceService\ElevenLabs\ElevenLabsVoiceClient;
use App\Classes\VoiceService\VoiceClientClonedVoice;
use App\Exceptions\Voices\ElevenLabsVoiceClientCouldNotAuthenticate;
use App\Exceptions\Voices\ElevenLabsVoiceClientCouldNotCloneVoice;
use App\Exceptions\Voices\ElevenLabsVoiceClientCouldNotAddSample;
use App\Models\Voice;
use App\Models\VoiceSample;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ElevenLabsVoiceClientTest extends TestCase
{

    public function setUp(): void
    {
        parent::setUp();
        
        // Setup storage fake
        Storage::fake('local');
        
        // Setup default config
        Config::set('voice.drivers.elevenlabs.base_url', 'https://api.elevenlabs.io');
        Config::set('voice.drivers.elevenlabs.api_key', 'test-api-key');
        Config::set('voice.drivers.elevenlabs.default_voice_settings', [
            'stability' => 0.75,
            'similarity_boost' => 0.75,
        ]);
    }

    #[Test]
    public function it_can_be_instantiated_with_valid_config(): void
    {
        $client = new ElevenLabsVoiceClient();
        
        $this->assertInstanceOf(ElevenLabsVoiceClient::class, $client);
    }

    #[Test]
    public function it_throws_exception_when_api_key_is_not_configured(): void
    {
        Config::set('voice.drivers.elevenlabs.api_key', null);
        
        $this->expectException(ElevenLabsVoiceClientCouldNotAuthenticate::class);
        $this->expectExceptionMessage('ElevenLabs API key is not configured');
        
        new ElevenLabsVoiceClient();
    }

    #[Test]
    public function it_throws_exception_when_api_key_is_empty(): void
    {
        Config::set('voice.drivers.elevenlabs.api_key', '');
        
        $this->expectException(ElevenLabsVoiceClientCouldNotAuthenticate::class);
        $this->expectExceptionMessage('ElevenLabs API key is not configured');
        
        new ElevenLabsVoiceClient();
    }

    #[Test]
    public function it_can_clone_voice_successfully(): void
    {
        // Arrange
        $voice = new class extends Voice {
            public function __construct() {
                $this->id = 1;
                $this->name = 'Test Voice';
                $this->description = 'A test voice for cloning';
            }
        };
        
        $voiceSample = new class extends VoiceSample {
            public function __construct() {
                $this->id = 1;
                $this->file = 'samples/test-audio.mp3';
            }
        };

        // Create a fake audio file
        Storage::put($voiceSample->file, 'fake-audio-content');

        // Mock the HTTP response
        Http::fake([
            'api.elevenlabs.io/v1/voices/add' => Http::response([
                'voice_id' => 'cloned-voice-id-123',
                'name' => 'Test Voice',
                'status' => 'ready',
            ], 200),
        ]);

        Log::shouldReceive('info')->times(2);

        $client = new ElevenLabsVoiceClient();

        // Act
        $result = $client->cloneVoice($voice, $voiceSample);

        // Assert
        $this->assertInstanceOf(VoiceClientClonedVoice::class, $result);
        $this->assertEquals('cloned-voice-id-123', $result->getProviderVoiceId());
        $this->assertTrue($result->isSuccessful());

        // Verify HTTP request was made correctly
        Http::assertSent(function ($request) use ($voice) {
            return $request->url() === 'https://api.elevenlabs.io/v1/voices/add' &&
                   $request->hasHeader('xi-api-key', 'test-api-key');
        });
    }

    #[Test]
    public function it_throws_exception_when_voice_sample_file_not_found(): void
    {
        // Arrange
        $voice = new class extends Voice {
            public function __construct() {
                $this->id = 1;
            }
        };
        
        $voiceSample = new class extends VoiceSample {
            public function __construct() {
                $this->id = 1;
                $this->file = 'samples/non-existent-file.mp3';
            }
        };

        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->once();

        $client = new ElevenLabsVoiceClient();

        // Act & Assert
        $this->expectException(ElevenLabsVoiceClientCouldNotCloneVoice::class);
        $this->expectExceptionMessage('ElevenLabs: Voice cloning failed:');
        
        $client->cloneVoice($voice, $voiceSample);
    }

    #[Test]
    public function it_throws_exception_when_clone_voice_api_fails(): void
    {
        // Arrange
        $voice = new class extends Voice {
            public function __construct() {
                $this->id = 1;
                $this->name = 'Test Voice';
            }
        };
        
        $voiceSample = new class extends VoiceSample {
            public function __construct() {
                $this->id = 1;
                $this->file = 'samples/test-audio.mp3';
            }
        };

        Storage::put($voiceSample->file, 'fake-audio-content');

        // Mock failed HTTP response
        Http::fake([
            'api.elevenlabs.io/v1/voices/add' => Http::response([
                'detail' => [
                    'message' => 'Invalid audio format'
                ]
            ], 400),
        ]);

        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->twice();

        $client = new ElevenLabsVoiceClient();

        // Act & Assert
        $this->expectException(ElevenLabsVoiceClientCouldNotCloneVoice::class);
        $this->expectExceptionMessage('Invalid audio format');
        
        $client->cloneVoice($voice, $voiceSample);
    }

    #[Test]
    public function it_can_add_voice_sample_successfully(): void
    {
        // Arrange
        $voice = new class extends Voice {
            public function __construct() {
                $this->id = 1;
                $this->source_voice_id = 'existing-voice-id-123';
            }
        };
        
        $voiceSample = new class extends VoiceSample {
            public function __construct() {
                $this->id = 1;
                $this->file = 'samples/test-audio.mp3';
            }
        };

        Storage::put($voiceSample->file, 'fake-audio-content');

        // Mock successful API response
        Http::fake([
            'api.elevenlabs.io/v1/voices/existing-voice-id-123/samples' => Http::response([
                'sample_id' => 'sample-123'
            ], 200),
        ]);

        Log::shouldReceive('info')->times(2); // One for start, one for success
        Log::shouldReceive('error')->zeroOrMoreTimes();

        $client = new ElevenLabsVoiceClient();

        // Act
        $result = $client->addVoice($voice, $voiceSample);
        
        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_throws_exception_when_voice_has_no_source_voice_id(): void
    {
        // Arrange
        $voice = new class extends Voice {
            public function __construct() {
                $this->id = 1;
                $this->source_voice_id = null;
            }
        };
        
        $voiceSample = new class extends VoiceSample {
            public function __construct() {
                $this->id = 1;
                $this->file = 'samples/test-audio.mp3';
            }
        };

        Storage::put($voiceSample->file, 'fake-audio-content');

        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->times(2); // One for missing source_voice_id, one for exception catch

        $client = new ElevenLabsVoiceClient();

        // Act & Assert
        $this->expectException(ElevenLabsVoiceClientCouldNotAddSample::class);
        $this->expectExceptionMessage('ElevenLabs: Voice must have a source_voice_id to add samples');
        
        $client->addVoice($voice, $voiceSample);
    }

    #[Test]
    public function it_throws_exception_when_add_voice_sample_file_not_found(): void
    {
        // Arrange
        $voice = new class extends Voice {
            public function __construct() {
                $this->id = 1;
                $this->source_voice_id = 'existing-voice-id-123';
            }
        };
        
        $voiceSample = new class extends VoiceSample {
            public function __construct() {
                $this->id = 1;
                $this->file = 'samples/non-existent-file.mp3';
            }
        };

        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->once();

        $client = new ElevenLabsVoiceClient();

        // Act & Assert
        $this->expectException(ElevenLabsVoiceClientCouldNotAddSample::class);
        $this->expectExceptionMessage('ElevenLabs: Failed to add voice sample:');
        
        $client->addVoice($voice, $voiceSample);
    }

    #[Test]
    public function it_throws_exception_when_add_voice_api_fails(): void
    {
        // Arrange
        $voice = new class extends Voice {
            public function __construct() {
                $this->id = 1;
                $this->source_voice_id = 'existing-voice-id-123';
            }
        };
        
        $voiceSample = new class extends VoiceSample {
            public function __construct() {
                $this->id = 1;
                $this->file = 'samples/test-audio.mp3';
            }
        };

        Storage::put($voiceSample->file, 'fake-audio-content');

        // Mock failed HTTP response
        Http::fake([
            'api.elevenlabs.io/v1/voices/existing-voice-id-123/samples' => Http::response([
                'error' => 'Sample limit exceeded'
            ], 400),
        ]);

        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->times(2); // One for API failure, one for exception catch

        $client = new ElevenLabsVoiceClient();

        // Act & Assert
        $this->expectException(ElevenLabsVoiceClientCouldNotAddSample::class);
        
        $client->addVoice($voice, $voiceSample);
    }

    #[Test]
    public function it_throws_exception_when_http_request_fails_for_clone_voice(): void
    {
        // Arrange
        $voice = new class extends Voice {
            public function __construct() {
                $this->id = 1;
                $this->name = 'Test Voice';
            }
        };
        
        $voiceSample = new class extends VoiceSample {
            public function __construct() {
                $this->id = 1;
                $this->file = 'samples/test-audio.mp3';
            }
        };

        Storage::put($voiceSample->file, 'fake-audio-content');

        // Mock HTTP exception
        Http::fake([
            'api.elevenlabs.io/v1/voices/add' => function () {
                throw new \Exception('Connection timeout');
            },
        ]);

        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->once();

        $client = new ElevenLabsVoiceClient();

        // Act & Assert
        $this->expectException(ElevenLabsVoiceClientCouldNotCloneVoice::class);
        $this->expectExceptionMessage('ElevenLabs: Voice cloning failed: Connection timeout');
        
        $client->cloneVoice($voice, $voiceSample);
    }

    #[Test]
    public function it_throws_exception_when_http_request_fails_for_add_voice(): void
    {
        // Arrange
        $voice = new class extends Voice {
            public function __construct() {
                $this->id = 1;
                $this->source_voice_id = 'existing-voice-id-123';
            }
        };
        
        $voiceSample = new class extends VoiceSample {
            public function __construct() {
                $this->id = 1;
                $this->file = 'samples/test-audio.mp3';
            }
        };

        Storage::put($voiceSample->file, 'fake-audio-content');

        // Mock HTTP exception
        Http::fake([
            'api.elevenlabs.io/v1/voices/existing-voice-id-123/samples' => function () {
                throw new \Exception('Network error');
            },
        ]);

        Log::shouldReceive('info')->once();
        Log::shouldReceive('error')->zeroOrMoreTimes();
        Log::shouldReceive('warning')->zeroOrMoreTimes();

        $client = new ElevenLabsVoiceClient();

        // Act & Assert
        $this->expectException(ElevenLabsVoiceClientCouldNotAddSample::class);
        
        $client->addVoice($voice, $voiceSample);
    }
}
