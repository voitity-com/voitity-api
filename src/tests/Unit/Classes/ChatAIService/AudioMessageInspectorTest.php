<?php

namespace Tests\Unit\Classes\ChatAIService;

use App\Classes\ChatAIService\AudioMessageInspector;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class AudioMessageInspectorTest extends TestCase
{
    #[Test]
    public function it_rounds_audio_duration_up_to_reserve_whole_seconds(): void
    {
        $analyzer = new class
        {
            public function analyze(string $path): array
            {
                return ['playtime_seconds' => 29.01];
            }
        };

        $duration = (new AudioMessageInspector($analyzer))
            ->durationSeconds(UploadedFile::fake()->create('message.webm', 10, 'audio/webm'));

        $this->assertSame(30, $duration);
    }

    #[Test]
    public function it_rejects_files_without_a_detectable_positive_duration(): void
    {
        $analyzer = new class
        {
            public function analyze(string $path): array
            {
                return [];
            }
        };

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Audio duration could not be determined.');

        (new AudioMessageInspector($analyzer))
            ->durationSeconds(UploadedFile::fake()->create('message.webm', 10, 'audio/webm'));
    }
}
