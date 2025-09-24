<?php

namespace Tests\Unit\Classes\VoiceService;

use App\Classes\VoiceService\VoiceClientGeneratedAudio;
use App\Models\Voice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceClientGeneratedAudioTest extends TestCase
{
    use RefreshDatabase;

    private Voice $voice;
    private string $sampleText;

    public function setUp(): void
    {
        parent::setUp();
        
        $this->voice = Voice::factory()->create([
            'name' => 'Test Voice',
            'source_voice_id' => 'test-voice-123',
        ]);
        
        $this->sampleText = 'Hello, this is a test audio generation.';
    }

    #[Test]
    public function it_can_be_instantiated_with_required_parameters(): void
    {
        // Act
        $generatedAudio = new VoiceClientGeneratedAudio($this->voice, $this->sampleText);

        // Assert
        $this->assertInstanceOf(VoiceClientGeneratedAudio::class, $generatedAudio);
        $this->assertSame($this->voice, $generatedAudio->voice);
        $this->assertEquals($this->sampleText, $generatedAudio->text);
        $this->assertNull($generatedAudio->audioUrl);
        $this->assertNull($generatedAudio->audioContent);
        $this->assertEquals('mp3', $generatedAudio->audioFormat);
        $this->assertNull($generatedAudio->duration);
        $this->assertEquals('pending', $generatedAudio->status);
        $this->assertEquals([], $generatedAudio->metadata);
    }

    #[Test]
    public function it_can_be_instantiated_with_all_parameters(): void
    {
        // Arrange
        $audioUrl = 'https://example.com/audio/generated.mp3';
        $audioContent = 'base64encodedaudiocontent';
        $audioFormat = 'wav';
        $duration = 15.5;
        $status = 'completed';
        $metadata = ['quality' => 'high', 'bitrate' => '320kbps'];

        // Act
        $generatedAudio = new VoiceClientGeneratedAudio(
            $this->voice,
            $this->sampleText,
            $audioUrl,
            $audioContent,
            $audioFormat,
            $duration,
            $status,
            $metadata
        );

        // Assert
        $this->assertSame($this->voice, $generatedAudio->voice);
        $this->assertEquals($this->sampleText, $generatedAudio->text);
        $this->assertEquals($audioUrl, $generatedAudio->audioUrl);
        $this->assertEquals($audioContent, $generatedAudio->audioContent);
        $this->assertEquals($audioFormat, $generatedAudio->audioFormat);
        $this->assertEquals($duration, $generatedAudio->duration);
        $this->assertEquals($status, $generatedAudio->status);
        $this->assertEquals($metadata, $generatedAudio->metadata);
    }

    #[Test]
    public function it_returns_true_for_is_successful_when_status_is_completed(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio(
            $this->voice,
            $this->sampleText,
            null,
            null,
            'mp3',
            null,
            'completed'
        );

        // Act
        $result = $generatedAudio->isSuccessful();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_true_for_is_successful_when_status_is_success(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio(
            $this->voice,
            $this->sampleText,
            null,
            null,
            'mp3',
            null,
            'success'
        );

        // Act
        $result = $generatedAudio->isSuccessful();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_for_is_successful_when_status_is_not_success_or_completed(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio(
            $this->voice,
            $this->sampleText,
            null,
            null,
            'mp3',
            null,
            'pending'
        );

        // Act
        $result = $generatedAudio->isSuccessful();

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function it_returns_true_for_is_failed_when_status_is_failed(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio(
            $this->voice,
            $this->sampleText,
            null,
            null,
            'mp3',
            null,
            'failed'
        );

        // Act
        $result = $generatedAudio->isFailed();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_true_for_is_failed_when_status_is_error(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio(
            $this->voice,
            $this->sampleText,
            null,
            null,
            'mp3',
            null,
            'error'
        );

        // Act
        $result = $generatedAudio->isFailed();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_for_is_failed_when_status_is_not_failed_or_error(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio(
            $this->voice,
            $this->sampleText,
            null,
            null,
            'mp3',
            null,
            'completed'
        );

        // Act
        $result = $generatedAudio->isFailed();

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function it_returns_true_for_is_pending_when_status_is_pending(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio(
            $this->voice,
            $this->sampleText,
            null,
            null,
            'mp3',
            null,
            'pending'
        );

        // Act
        $result = $generatedAudio->isPending();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_true_for_is_pending_when_status_is_processing(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio(
            $this->voice,
            $this->sampleText,
            null,
            null,
            'mp3',
            null,
            'processing'
        );

        // Act
        $result = $generatedAudio->isPending();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_for_is_pending_when_status_is_not_pending_or_processing(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio(
            $this->voice,
            $this->sampleText,
            null,
            null,
            'mp3',
            null,
            'completed'
        );

        // Act
        $result = $generatedAudio->isPending();

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function it_returns_true_for_has_audio_content_when_audio_content_exists(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio(
            $this->voice,
            $this->sampleText,
            null,
            'some-audio-content'
        );

        // Act
        $result = $generatedAudio->hasAudioContent();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_true_for_has_audio_content_when_audio_url_exists(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio(
            $this->voice,
            $this->sampleText,
            'https://example.com/audio.mp3'
        );

        // Act
        $result = $generatedAudio->hasAudioContent();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_true_for_has_audio_content_when_both_url_and_content_exist(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio(
            $this->voice,
            $this->sampleText,
            'https://example.com/audio.mp3',
            'some-audio-content'
        );

        // Act
        $result = $generatedAudio->hasAudioContent();

        // Assert
        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_for_has_audio_content_when_no_audio_available(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio($this->voice, $this->sampleText);

        // Act
        $result = $generatedAudio->hasAudioContent();

        // Assert
        $this->assertFalse($result);
    }

    #[Test]
    public function it_can_get_audio_url(): void
    {
        // Arrange
        $audioUrl = 'https://example.com/generated-audio.mp3';
        $generatedAudio = new VoiceClientGeneratedAudio(
            $this->voice,
            $this->sampleText,
            $audioUrl
        );

        // Act
        $result = $generatedAudio->getAudioUrl();

        // Assert
        $this->assertEquals($audioUrl, $result);
    }

    #[Test]
    public function it_returns_null_when_audio_url_is_not_set(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio($this->voice, $this->sampleText);

        // Act
        $result = $generatedAudio->getAudioUrl();

        // Assert
        $this->assertNull($result);
    }

    #[Test]
    public function it_can_set_audio_url(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio($this->voice, $this->sampleText);
        $newAudioUrl = 'https://example.com/new-audio.mp3';

        // Act
        $result = $generatedAudio->setAudioUrl($newAudioUrl);

        // Assert
        $this->assertSame($generatedAudio, $result); // Should return self for fluent interface
        $this->assertEquals($newAudioUrl, $generatedAudio->audioUrl);
        $this->assertEquals($newAudioUrl, $generatedAudio->getAudioUrl());
    }

    #[Test]
    public function it_can_get_audio_content(): void
    {
        // Arrange
        $audioContent = 'base64encodedaudiodata';
        $generatedAudio = new VoiceClientGeneratedAudio(
            $this->voice,
            $this->sampleText,
            null,
            $audioContent
        );

        // Act
        $result = $generatedAudio->getAudioContent();

        // Assert
        $this->assertEquals($audioContent, $result);
    }

    #[Test]
    public function it_returns_null_when_audio_content_is_not_set(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio($this->voice, $this->sampleText);

        // Act
        $result = $generatedAudio->getAudioContent();

        // Assert
        $this->assertNull($result);
    }

    #[Test]
    public function it_can_set_audio_content(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio($this->voice, $this->sampleText);
        $newAudioContent = 'newbase64encodedaudiodata';

        // Act
        $result = $generatedAudio->setAudioContent($newAudioContent);

        // Assert
        $this->assertSame($generatedAudio, $result); // Should return self for fluent interface
        $this->assertEquals($newAudioContent, $generatedAudio->audioContent);
        $this->assertEquals($newAudioContent, $generatedAudio->getAudioContent());
    }

    #[Test]
    public function it_can_get_duration(): void
    {
        // Arrange
        $duration = 23.7;
        $generatedAudio = new VoiceClientGeneratedAudio(
            $this->voice,
            $this->sampleText,
            null,
            null,
            'mp3',
            $duration
        );

        // Act
        $result = $generatedAudio->getDuration();

        // Assert
        $this->assertEquals($duration, $result);
    }

    #[Test]
    public function it_returns_null_when_duration_is_not_set(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio($this->voice, $this->sampleText);

        // Act
        $result = $generatedAudio->getDuration();

        // Assert
        $this->assertNull($result);
    }

    #[Test]
    public function it_can_set_duration(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio($this->voice, $this->sampleText);
        $newDuration = 42.8;

        // Act
        $result = $generatedAudio->setDuration($newDuration);

        // Assert
        $this->assertSame($generatedAudio, $result); // Should return self for fluent interface
        $this->assertEquals($newDuration, $generatedAudio->duration);
        $this->assertEquals($newDuration, $generatedAudio->getDuration());
    }

    #[Test]
    public function it_can_convert_to_array_with_all_fields(): void
    {
        // Arrange
        $audioUrl = 'https://example.com/audio.mp3';
        $audioFormat = 'wav';
        $duration = 18.3;
        $status = 'completed';
        $metadata = ['quality' => 'high', 'bitrate' => '256kbps'];
        
        $generatedAudio = new VoiceClientGeneratedAudio(
            $this->voice,
            $this->sampleText,
            $audioUrl,
            'audio-content',
            $audioFormat,
            $duration,
            $status,
            $metadata
        );

        // Act
        $result = $generatedAudio->toArray();

        // Assert
        $expected = [
            'voice_id' => $this->voice->id,
            'text' => $this->sampleText,
            'audio_url' => $audioUrl,
            'audio_format' => $audioFormat,
            'duration' => $duration,
            'status' => $status,
            'metadata' => $metadata,
        ];
        
        $this->assertEquals($expected, $result);
    }

    #[Test]
    public function it_can_convert_to_array_with_minimal_fields(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio($this->voice, $this->sampleText);

        // Act
        $result = $generatedAudio->toArray();

        // Assert
        $expected = [
            'voice_id' => $this->voice->id,
            'text' => $this->sampleText,
            'audio_url' => null,
            'audio_format' => 'mp3',
            'duration' => null,
            'status' => 'pending',
            'metadata' => [],
        ];
        
        $this->assertEquals($expected, $result);
    }

    #[Test]
    public function it_maintains_voice_reference(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio($this->voice, $this->sampleText);

        // Act & Assert - Test that the voice reference is maintained
        $this->assertEquals($this->voice->id, $generatedAudio->voice->id);
        $this->assertEquals($this->voice->name, $generatedAudio->voice->name);
        $this->assertEquals($this->voice->source_voice_id, $generatedAudio->voice->source_voice_id);
    }

    #[Test]
    public function it_can_be_created_with_different_status_values(): void
    {
        $statusValues = ['pending', 'processing', 'completed', 'success', 'failed', 'error', 'cancelled'];

        foreach ($statusValues as $status) {
            // Act
            $generatedAudio = new VoiceClientGeneratedAudio(
                $this->voice,
                $this->sampleText,
                null,
                null,
                'mp3',
                null,
                $status
            );

            // Assert
            $this->assertEquals($status, $generatedAudio->status);
        }
    }

    #[Test]
    public function it_can_be_created_with_different_audio_formats(): void
    {
        $audioFormats = ['mp3', 'wav', 'flac', 'aac', 'ogg'];

        foreach ($audioFormats as $format) {
            // Act
            $generatedAudio = new VoiceClientGeneratedAudio(
                $this->voice,
                $this->sampleText,
                null,
                null,
                $format
            );

            // Assert
            $this->assertEquals($format, $generatedAudio->audioFormat);
        }
    }

    #[Test]
    public function it_can_handle_complex_metadata(): void
    {
        // Arrange
        $complexMetadata = [
            'provider_response' => [
                'model' => 'eleven_multilingual_v2',
                'settings' => [
                    'stability' => 0.5,
                    'similarity_boost' => 0.8,
                    'style' => 0.2,
                ],
            ],
            'processing_time' => 3.2,
            'characters_count' => strlen($this->sampleText),
            'estimated_cost' => 0.002,
            'quality_metrics' => [
                'clarity' => 0.95,
                'naturalness' => 0.87,
            ],
        ];

        $generatedAudio = new VoiceClientGeneratedAudio(
            $this->voice,
            $this->sampleText,
            'https://example.com/audio.mp3',
            null,
            'mp3',
            10.5,
            'completed',
            $complexMetadata
        );

        // Act
        $result = $generatedAudio->toArray();

        // Assert
        $this->assertEquals($complexMetadata, $result['metadata']);
        $this->assertEquals($complexMetadata['processing_time'], $generatedAudio->metadata['processing_time']);
    }

    #[Test]
    public function it_supports_fluent_interface_for_setting_properties(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio($this->voice, $this->sampleText);
        $audioUrl = 'https://example.com/fluent-audio.mp3';
        $audioContent = 'fluent-audio-content';
        $duration = 25.6;

        // Act - Test fluent interface
        $result = $generatedAudio->setAudioUrl($audioUrl)
            ->setAudioContent($audioContent)
            ->setDuration($duration);

        // Assert
        $this->assertSame($generatedAudio, $result);
        $this->assertEquals($audioUrl, $generatedAudio->getAudioUrl());
        $this->assertEquals($audioContent, $generatedAudio->getAudioContent());
        $this->assertEquals($duration, $generatedAudio->getDuration());
    }

    #[Test]
    public function it_handles_zero_duration(): void
    {
        // Arrange
        $generatedAudio = new VoiceClientGeneratedAudio(
            $this->voice,
            $this->sampleText,
            null,
            null,
            'mp3',
            0.0
        );

        // Act
        $duration = $generatedAudio->getDuration();

        // Assert
        $this->assertEquals(0.0, $duration);
        $this->assertIsFloat($duration);
    }

    #[Test]
    public function it_handles_long_text_content(): void
    {
        // Arrange
        $longText = str_repeat('This is a very long text that will be used to test audio generation. ', 100);
        $generatedAudio = new VoiceClientGeneratedAudio($this->voice, $longText);

        // Act & Assert
        $this->assertEquals($longText, $generatedAudio->text);
        $this->assertEquals(strlen($longText), strlen($generatedAudio->text));
    }

    #[Test]
    public function it_handles_special_characters_in_text(): void
    {
        // Arrange
        $specialText = 'Hello! How are you today? I\'m fine, thanks. Cost: $19.99 (20% off). Email: test@example.com.';
        $generatedAudio = new VoiceClientGeneratedAudio($this->voice, $specialText);

        // Act & Assert
        $this->assertEquals($specialText, $generatedAudio->text);
    }
}
