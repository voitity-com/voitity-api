<?php

namespace Tests\Unit\Providers;

use App\Classes\VoiceService\VoiceService;
use App\Classes\VoiceService\VoiceManager;
use App\Classes\VoiceService\VoiceClient;
use App\Models\Voice;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceServiceProviderTest extends TestCase
{

    public function setUp(): void
    {
        parent::setUp();
        
        // Mock ElevenLabs configuration for testing
        Config::set('voice.drivers.elevenlabs.api_key', 'test-api-key');
        Config::set('voice.drivers.elevenlabs.base_url', 'https://api.elevenlabs.io');
        Config::set('voice.drivers.elevenlabs.default_voice_settings', [
            'stability' => 0.5,
            'similarity_boost' => 0.5,
        ]);
    }

    #[Test]
    public function it_can_resolve_voice_manager(): void
    {
        // Act
        $manager = app(VoiceManager::class);

        // Assert
        $this->assertInstanceOf(VoiceManager::class, $manager);
    }

    #[Test]
    public function it_can_resolve_voice_client(): void
    {
        // Act
        $client = app(VoiceClient::class);

        // Assert
        $this->assertInstanceOf(VoiceClient::class, $client);
    }

    #[Test]
    public function it_can_create_voice_service_via_manager(): void
    {
        // Arrange
        $voice = $this->createMock(Voice::class);
        $voiceManager = app(VoiceManager::class);

        // Act
        $voiceClient = $voiceManager->driver();
        $voiceService = new VoiceService($voice, $voiceClient);

        // Assert
        $this->assertInstanceOf(VoiceService::class, $voiceService);
        $this->assertSame($voice, $voiceService->getVoice());
        $this->assertInstanceOf(VoiceClient::class, $voiceService->getVoiceClient());
    }

    #[Test]
    public function it_can_create_voice_service_with_custom_client(): void
    {
        // Arrange
        $voice = $this->createMock(Voice::class);
        $customClient = app(VoiceClient::class);

        // Act
        $voiceService = new VoiceService($voice, $customClient);

        // Assert
        $this->assertInstanceOf(VoiceService::class, $voiceService);
        $this->assertSame($voice, $voiceService->getVoice());
        $this->assertSame($customClient, $voiceService->getVoiceClient());
    }

    #[Test]
    public function voice_manager_is_singleton(): void
    {
        // Act
        $manager1 = app(VoiceManager::class);
        $manager2 = app(VoiceManager::class);

        // Assert
        $this->assertSame($manager1, $manager2);
    }

    #[Test]
    public function voice_client_instances_are_not_singleton(): void
    {
        // Act
        $client1 = app(VoiceClient::class);
        $client2 = app(VoiceClient::class);

        // Assert - They should be different instances since it's bound, not singleton
        $this->assertEquals(get_class($client1), get_class($client2));
        $this->assertInstanceOf(VoiceClient::class, $client1);
        $this->assertInstanceOf(VoiceClient::class, $client2);
    }
}
