<?php

namespace Tests\Unit\Classes\VoiceService;

use App\Classes\VoiceService\VoiceClientClonedVoice;
use App\Models\Voice;
use App\Models\VoiceSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceClientClonedVoiceTest extends TestCase
{
    use RefreshDatabase;

    private Voice $voice;
    private VoiceSample $voiceSample;

    public function setUp(): void
    {
        parent::setUp();
        
        $this->voice = Voice::factory()->create([
            'name' => 'Test Voice',
            'source_voice_id' => 'test-voice-123',
        ]);
        
        $this->voiceSample = VoiceSample::factory()->create([
            'voice_id' => $this->voice->id,
            'file' => 'samples/test-audio.mp3',
        ]);
    }

    #[Test]
    public function it_can_be_instantiated_with_required_parameters(): void
    {
        // Act
        $clonedVoice = new VoiceClientClonedVoice($this->voice, $this->voiceSample);

        // Assert
        $this->assertInstanceOf(VoiceClientClonedVoice::class, $clonedVoice);
        $this->assertSame($this->voice, $clonedVoice->voice);
        $this->assertSame($this->voiceSample, $clonedVoice->voiceSample);
        $this->assertNull($clonedVoice->providerVoiceId);
        $this->assertEquals('pending', $clonedVoice->status);
        $this->assertEquals([], $clonedVoice->metadata);
    }

    #[Test]
    public function it_can_be_instantiated_with_all_parameters(): void
    {
        // Arrange
        $providerVoiceId = 'provider-voice-456';
        $status = 'completed';
        $metadata = ['quality' => 'high', 'duration' => '10s'];

        // Act
        $clonedVoice = new VoiceClientClonedVoice(
            $this->voice,
            $this->voiceSample,
            $providerVoiceId,
            $status,
            $metadata
        );

        // Assert
        $this->assertSame($this->voice, $clonedVoice->voice);
        $this->assertSame($this->voiceSample, $clonedVoice->voiceSample);
        $this->assertEquals($providerVoiceId, $clonedVoice->providerVoiceId);
        $this->assertEquals($status, $clonedVoice->status);
        $this->assertEquals($metadata, $clonedVoice->metadata);
    }

    #[Test]
    public function it_returns_true_for_is_successful_when_status_is_completed(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice(
            $this->voice,
            $this->voiceSample,
            null,
            'completed'
        );

        // Act
        $result = $clonedVoice->isSuccessful();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_true_for_is_successful_when_status_is_success(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice(
            $this->voice,
            $this->voiceSample,
            null,
            'success'
        );

        // Act
        $result = $clonedVoice->isSuccessful();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_for_is_successful_when_status_is_not_success_or_completed(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice(
            $this->voice,
            $this->voiceSample,
            null,
            'pending'
        );

        // Act
        $result = $clonedVoice->isSuccessful();

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function it_returns_true_for_is_failed_when_status_is_failed(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice(
            $this->voice,
            $this->voiceSample,
            null,
            'failed'
        );

        // Act
        $result = $clonedVoice->isFailed();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_true_for_is_failed_when_status_is_error(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice(
            $this->voice,
            $this->voiceSample,
            null,
            'error'
        );

        // Act
        $result = $clonedVoice->isFailed();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_for_is_failed_when_status_is_not_failed_or_error(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice(
            $this->voice,
            $this->voiceSample,
            null,
            'completed'
        );

        // Act
        $result = $clonedVoice->isFailed();

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function it_returns_true_for_is_pending_when_status_is_pending(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice(
            $this->voice,
            $this->voiceSample,
            null,
            'pending'
        );

        // Act
        $result = $clonedVoice->isPending();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_true_for_is_pending_when_status_is_processing(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice(
            $this->voice,
            $this->voiceSample,
            null,
            'processing'
        );

        // Act
        $result = $clonedVoice->isPending();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_for_is_pending_when_status_is_not_pending_or_processing(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice(
            $this->voice,
            $this->voiceSample,
            null,
            'completed'
        );

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
        $clonedVoice = new VoiceClientClonedVoice(
            $this->voice,
            $this->voiceSample,
            $providerVoiceId
        );

        // Act
        $result = $clonedVoice->getProviderVoiceId();

        // Assert
        $this->assertEquals($providerVoiceId, $result);
    }

    #[Test]
    public function it_returns_null_when_provider_voice_id_is_not_set(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice($this->voice, $this->voiceSample);

        // Act
        $result = $clonedVoice->getProviderVoiceId();

        // Assert
        $this->assertNull($result);
    }

    #[Test]
    public function it_can_set_provider_voice_id(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice($this->voice, $this->voiceSample);
        $newProviderVoiceId = 'new-provider-voice-123';

        // Act
        $result = $clonedVoice->setProviderVoiceId($newProviderVoiceId);

        // Assert
        $this->assertSame($clonedVoice, $result); // Should return self for fluent interface
        $this->assertEquals($newProviderVoiceId, $clonedVoice->providerVoiceId);
        $this->assertEquals($newProviderVoiceId, $clonedVoice->getProviderVoiceId());
    }

    #[Test]
    public function it_can_convert_to_array_with_all_fields(): void
    {
        // Arrange
        $providerVoiceId = 'provider-voice-456';
        $status = 'completed';
        $metadata = ['quality' => 'high', 'duration' => '10s'];
        
        $clonedVoice = new VoiceClientClonedVoice(
            $this->voice,
            $this->voiceSample,
            $providerVoiceId,
            $status,
            $metadata
        );

        // Act
        $result = $clonedVoice->toArray();

        // Assert
        $expected = [
            'voice_id' => $this->voice->id,
            'voice_sample_id' => $this->voiceSample->id,
            'provider_voice_id' => $providerVoiceId,
            'status' => $status,
            'metadata' => $metadata,
        ];
        
        $this->assertEquals($expected, $result);
    }

    #[Test]
    public function it_can_convert_to_array_with_minimal_fields(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice($this->voice, $this->voiceSample);

        // Act
        $result = $clonedVoice->toArray();

        // Assert
        $expected = [
            'voice_id' => $this->voice->id,
            'voice_sample_id' => $this->voiceSample->id,
            'provider_voice_id' => null,
            'status' => 'pending',
            'metadata' => [],
        ];
        
        $this->assertEquals($expected, $result);
    }

    #[Test]
    public function it_maintains_voice_and_voice_sample_references(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice($this->voice, $this->voiceSample);

        // Act & Assert - Test that the references are maintained
        $this->assertEquals($this->voice->id, $clonedVoice->voice->id);
        $this->assertEquals($this->voice->name, $clonedVoice->voice->name);
        $this->assertEquals($this->voiceSample->id, $clonedVoice->voiceSample->id);
        $this->assertEquals($this->voiceSample->file, $clonedVoice->voiceSample->file);
    }

    #[Test]
    public function it_can_be_created_with_different_status_values(): void
    {
        $statusValues = ['pending', 'processing', 'completed', 'success', 'failed', 'error', 'cancelled'];

        foreach ($statusValues as $status) {
            // Act
            $clonedVoice = new VoiceClientClonedVoice(
                $this->voice,
                $this->voiceSample,
                null,
                $status
            );

            // Assert
            $this->assertEquals($status, $clonedVoice->status);
        }
    }

    #[Test]
    public function it_can_handle_complex_metadata(): void
    {
        // Arrange
        $complexMetadata = [
            'provider_response' => [
                'voice_id' => 'ext-123',
                'settings' => [
                    'stability' => 0.75,
                    'similarity_boost' => 0.85,
                ],
            ],
            'processing_time' => 15.7,
            'quality_score' => 0.92,
            'errors' => [],
            'warnings' => ['Low quality input audio detected'],
        ];

        $clonedVoice = new VoiceClientClonedVoice(
            $this->voice,
            $this->voiceSample,
            'ext-voice-123',
            'completed',
            $complexMetadata
        );

        // Act
        $result = $clonedVoice->toArray();

        // Assert
        $this->assertEquals($complexMetadata, $result['metadata']);
        $this->assertEquals($complexMetadata['processing_time'], $clonedVoice->metadata['processing_time']);
    }

    #[Test]
    public function it_supports_fluent_interface_for_setting_provider_voice_id(): void
    {
        // Arrange
        $clonedVoice = new VoiceClientClonedVoice($this->voice, $this->voiceSample);
        $providerVoiceId = 'fluent-voice-123';

        // Act - Test fluent interface
        $result = $clonedVoice->setProviderVoiceId($providerVoiceId)
            ->setProviderVoiceId('updated-voice-456');

        // Assert
        $this->assertSame($clonedVoice, $result);
        $this->assertEquals('updated-voice-456', $clonedVoice->getProviderVoiceId());
    }
}
