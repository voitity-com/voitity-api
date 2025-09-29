<?php

namespace Tests\Unit\Classes\VoiceService;

use App\Classes\VoiceService\VoiceClientClonedVoice;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceClientClonedVoiceTest extends TestCase
{
    #[Test]
    public function it_can_be_instantiated_with_required_parameters(): void
    {
        // Act
        $clonedVoice = new VoiceClientClonedVoice('elevenlabs');

        // Assert
        $this->assertInstanceOf(VoiceClientClonedVoice::class, $clonedVoice);
        $this->assertEquals('elevenlabs', $clonedVoice->source);
        $this->assertNull($clonedVoice->providerVoiceId);
        $this->assertEquals('pending', $clonedVoice->status);
        $this->assertEquals([], $clonedVoice->response);
    }

    #[Test]
    public function it_can_be_instantiated_with_all_parameters(): void
    {
        // Arrange
        $source = 'elevenlabs';
        $providerVoiceId = 'provider-voice-456';
        $status = 'completed';
        $response = ['quality' => 'high', 'duration' => '10s'];
        $requestUrl = 'https://api.elevenlabs.io/v1/voice-generation/create-voice';

                // Act
        $clonedVoice = new VoiceClientClonedVoice($source, $providerVoiceId, $status, $response, $requestUrl);

        // Assert
        $this->assertInstanceOf(VoiceClientClonedVoice::class, $clonedVoice);
        $this->assertEquals($source, $clonedVoice->source);
        $this->assertEquals($providerVoiceId, $clonedVoice->providerVoiceId);
        $this->assertEquals($status, $clonedVoice->status);
        $this->assertEquals($response, $clonedVoice->response);
        $this->assertEquals($requestUrl, $clonedVoice->requestUrl);
    }

    #[Test]
    public function it_returns_true_for_is_successful_when_status_is_completed(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice('elevenlabs', null, 'completed');

        // Act
        $result = $clonedVoice->isSuccessful();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_true_for_is_successful_when_status_is_success(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice('elevenlabs', null, 'success');

        // Act
        $result = $clonedVoice->isSuccessful();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_for_is_successful_when_status_is_pending(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice('elevenlabs', null, 'pending');

        // Act
        $result = $clonedVoice->isSuccessful();

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function it_returns_true_for_is_failed_when_status_is_failed(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice('elevenlabs', null, 'failed');

        // Act
        $result = $clonedVoice->isFailed();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_true_for_is_failed_when_status_is_error(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice('elevenlabs', null, 'error');

        // Act
        $result = $clonedVoice->isFailed();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_for_is_failed_when_status_is_completed(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice('elevenlabs', null, 'completed');

        // Act
        $result = $clonedVoice->isFailed();

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function it_returns_true_for_is_pending_when_status_is_pending(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice('elevenlabs', null, 'pending');

        // Act
        $result = $clonedVoice->isPending();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_true_for_is_pending_when_status_is_processing(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice('elevenlabs', null, 'processing');

        // Act
        $result = $clonedVoice->isPending();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_for_is_pending_when_status_is_completed(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice('elevenlabs', null, 'completed');

        // Act
        $result = $clonedVoice->isPending();

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function it_can_get_provider_voice_id(): void
    {
        // Arrange
        $providerVoiceId = 'provider-voice-789';
        $clonedVoice = new VoiceClientClonedVoice('elevenlabs', $providerVoiceId);

        // Act
        $result = $clonedVoice->getProviderVoiceId();

        // Assert
        $this->assertEquals($providerVoiceId, $result);
    }

    #[Test]
    public function it_can_set_provider_voice_id(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice('elevenlabs');
        $newProviderVoiceId = 'new-provider-voice-123';

        // Act
        $result = $clonedVoice->setProviderVoiceId($newProviderVoiceId);

        // Assert
        $this->assertSame($clonedVoice, $result);
        $this->assertEquals($newProviderVoiceId, $clonedVoice->providerVoiceId);
    }

    #[Test]
    public function it_returns_fluent_interface_when_setting_provider_voice_id(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice('elevenlabs');

        // Act & Assert
        $this->assertSame(
            $clonedVoice,
            $clonedVoice->setProviderVoiceId('test-id')
        );
    }

    #[Test]
    public function it_can_convert_to_array(): void
    {
        // Arrange
        $source = 'elevenlabs';
        $providerVoiceId = 'provider-voice-456';
        $status = 'completed';
        $response = ['quality' => 'high'];
        
        $clonedVoice = new VoiceClientClonedVoice(
            $source,
            $providerVoiceId,
            $status,
            $response
        );

        // Act
        $result = $clonedVoice->toArray();

        // Assert
        $this->assertEquals([
            'source' => $source,
            'provider_voice_id' => $providerVoiceId,
            'status' => $status,
            'request_url' => null,
            'response' => $response,
        ], $result);
    }

    #[Test]
    public function it_can_convert_to_array_with_null_provider_voice_id(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice('elevenlabs');

        // Act
        $result = $clonedVoice->toArray();

        // Assert
        $this->assertEquals([
            'source' => 'elevenlabs',
            'provider_voice_id' => null,
            'status' => 'pending',
            'request_url' => null,
            'response' => [],
        ], $result);
    }

    #[Test]
    public function it_supports_different_source_providers(): void
    {
        // Test different source providers
        $sources = ['elevenlabs', 'aws-polly', 'google-cloud', 'azure'];
        
        foreach ($sources as $source) {
            $clonedVoice = new VoiceClientClonedVoice($source);
            $this->assertEquals($source, $clonedVoice->source);
        }
    }

    #[Test]
    public function it_handles_complex_response_data(): void
    {
        // Arrange
        $complexResponse = [
            'quality' => 'high',
            'duration' => '30s',
            'sample_rate' => 48000,
            'nested' => [
                'key1' => 'value1',
                'key2' => ['nested_array']
            ]
        ];
        
        $clonedVoice = new VoiceClientClonedVoice('elevenlabs', 'test-id', 'completed', $complexResponse);

        // Act
        $result = $clonedVoice->toArray();

        // Assert
        $this->assertEquals($complexResponse, $result['response']);
    }

    #[Test]
    public function it_supports_method_chaining_with_provider_voice_id(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice('elevenlabs');

        // Act & Assert
        $result = $clonedVoice
            ->setProviderVoiceId('first-id')
            ->setProviderVoiceId('final-id');

        $this->assertSame($clonedVoice, $result);
        $this->assertEquals('final-id', $clonedVoice->getProviderVoiceId());
    }
}
