<?php

namespace Tests\Unit\Classes\VoiceService;

use App\Classes\VoiceService\VoiceClientAddedSample;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceClientAddedSampleTest extends TestCase
{
    #[Test]
    public function it_can_be_instantiated_with_required_parameters(): void
    {
        // Act
        $addedSample = new VoiceClientAddedSample('elevenlabs');

        // Assert
        $this->assertInstanceOf(VoiceClientAddedSample::class, $addedSample);
        $this->assertEquals('elevenlabs', $addedSample->source);
        $this->assertEquals('pending', $addedSample->status);
        $this->assertNull($addedSample->requestUrl);
        $this->assertEquals([], $addedSample->response);
    }

    #[Test]
    public function it_can_be_instantiated_with_all_parameters(): void
    {
        // Arrange
        $source = 'elevenlabs';
        $status = 'completed';
        $response = ['sample_id' => 'sample-123'];
        $requestUrl = 'https://api.elevenlabs.io/v1/voices/voice-123/samples';

        // Act
        $addedSample = new VoiceClientAddedSample($source, $status, $response, $requestUrl);

        // Assert
        $this->assertInstanceOf(VoiceClientAddedSample::class, $addedSample);
        $this->assertEquals($source, $addedSample->source);
        $this->assertEquals($status, $addedSample->status);
        $this->assertEquals($response, $addedSample->response);
        $this->assertEquals($requestUrl, $addedSample->requestUrl);
    }

    #[Test]
    public function it_returns_true_for_is_successful_when_status_is_completed(): void
    {
        // Arrange
        $addedSample = new VoiceClientAddedSample('elevenlabs', 'completed');

        // Act & Assert
        $this->assertTrue($addedSample->isSuccessful());
        $this->assertFalse($addedSample->isFailed());
        $this->assertFalse($addedSample->isPending());
    }

    #[Test]
    public function it_returns_true_for_is_successful_when_status_is_success(): void
    {
        // Arrange
        $addedSample = new VoiceClientAddedSample('elevenlabs', 'success');

        // Act & Assert
        $this->assertTrue($addedSample->isSuccessful());
        $this->assertFalse($addedSample->isFailed());
        $this->assertFalse($addedSample->isPending());
    }

    #[Test]
    public function it_returns_false_for_is_successful_when_status_is_pending(): void
    {
        // Arrange
        $addedSample = new VoiceClientAddedSample('elevenlabs', 'pending');

        // Act & Assert
        $this->assertFalse($addedSample->isSuccessful());
        $this->assertFalse($addedSample->isFailed());
        $this->assertTrue($addedSample->isPending());
    }

    #[Test]
    public function it_returns_true_for_is_failed_when_status_is_failed(): void
    {
        // Arrange
        $addedSample = new VoiceClientAddedSample('elevenlabs', 'failed');

        // Act & Assert
        $this->assertFalse($addedSample->isSuccessful());
        $this->assertTrue($addedSample->isFailed());
        $this->assertFalse($addedSample->isPending());
    }

    #[Test]
    public function it_returns_true_for_is_failed_when_status_is_error(): void
    {
        // Arrange
        $addedSample = new VoiceClientAddedSample('elevenlabs', 'error');

        // Act & Assert
        $this->assertFalse($addedSample->isSuccessful());
        $this->assertTrue($addedSample->isFailed());
        $this->assertFalse($addedSample->isPending());
    }

    #[Test]
    public function it_returns_false_for_is_failed_when_status_is_completed(): void
    {
        // Arrange
        $addedSample = new VoiceClientAddedSample('elevenlabs', 'completed');

        // Act & Assert
        $this->assertTrue($addedSample->isSuccessful());
        $this->assertFalse($addedSample->isFailed());
        $this->assertFalse($addedSample->isPending());
    }

    #[Test]
    public function it_returns_true_for_is_pending_when_status_is_pending(): void
    {
        // Arrange
        $addedSample = new VoiceClientAddedSample('elevenlabs', 'pending');

        // Act & Assert
        $this->assertFalse($addedSample->isSuccessful());
        $this->assertFalse($addedSample->isFailed());
        $this->assertTrue($addedSample->isPending());
    }

    #[Test]
    public function it_returns_true_for_is_pending_when_status_is_processing(): void
    {
        // Arrange
        $addedSample = new VoiceClientAddedSample('elevenlabs', 'processing');

        // Act & Assert
        $this->assertFalse($addedSample->isSuccessful());
        $this->assertFalse($addedSample->isFailed());
        $this->assertTrue($addedSample->isPending());
    }

    #[Test]
    public function it_returns_false_for_is_pending_when_status_is_completed(): void
    {
        // Arrange
        $addedSample = new VoiceClientAddedSample('elevenlabs', 'completed');

        // Act & Assert
        $this->assertTrue($addedSample->isSuccessful());
        $this->assertFalse($addedSample->isFailed());
        $this->assertFalse($addedSample->isPending());
    }

    #[Test]
    public function it_can_convert_to_array(): void
    {
        // Arrange
        $source = 'elevenlabs';
        $status = 'completed';
        $response = ['sample_id' => 'sample-123'];
        $requestUrl = 'https://api.elevenlabs.io/v1/voices/voice-123/samples';
        
        $addedSample = new VoiceClientAddedSample($source, $status, $response, $requestUrl);

        // Act
        $result = $addedSample->toArray();

        // Assert
        $this->assertEquals([
            'source' => $source,
            'status' => $status,
            'request_url' => $requestUrl,
            'response' => $response,
        ], $result);
    }

    #[Test]
    public function it_can_convert_to_array_with_null_request_url(): void
    {
        // Arrange
        $addedSample = new VoiceClientAddedSample('elevenlabs');

        // Act
        $result = $addedSample->toArray();

        // Assert
        $this->assertEquals([
            'source' => 'elevenlabs',
            'status' => 'pending',
            'request_url' => null,
            'response' => [],
        ], $result);
    }

    #[Test]
    public function it_supports_different_source_providers(): void
    {
        // Test different source providers
        $providers = ['elevenlabs', 'azure', 'google', 'aws'];
        
        foreach ($providers as $source) {
            $addedSample = new VoiceClientAddedSample($source);
            $this->assertEquals($source, $addedSample->source);
        }
    }

    #[Test]
    public function it_handles_complex_response_data(): void
    {
        // Arrange
        $complexResponse = [
            'sample_id' => 'sample-123',
            'quality' => 'high',
            'duration' => '30s',
            'nested' => [
                'key1' => 'value1',
                'key2' => ['nested_array']
            ]
        ];
        
        $addedSample = new VoiceClientAddedSample('elevenlabs', 'completed', $complexResponse);

        // Act
        $result = $addedSample->toArray();

        // Assert
        $this->assertEquals($complexResponse, $result['response']);
    }
}
