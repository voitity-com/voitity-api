<?php

namespace Tests\Unit\Classes;

use App\Classes\VoiceSampleFileManager;
use App\Exceptions\Voices\VoiceSampleFileManagerCouldNotProcessSample;
use getID3;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Mockery;

class VoiceSampleFileManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_process_sample_file_success()
    {
        // Mock Storage facade
        Storage::fake('local');
        
        // Mock getID3
        $getID3Mock = Mockery::mock(getID3::class);
        $getID3Mock->shouldReceive('analyze')
            ->once()
            ->andReturn(['playtime_seconds' => 125.5]);

        // Mock UploadedFile
        $fileMock = Mockery::mock(UploadedFile::class);
        $fileMock->shouldReceive('getClientOriginalExtension')
            ->once()
            ->andReturn('mp3');
        $fileMock->shouldReceive('getPathname')
            ->once()
            ->andReturn('/tmp/test.mp3');
        
        // Create instance with mocked getID3
        $fileManager = new VoiceSampleFileManager($getID3Mock);
        
        // Mock Storage::putFileAs to return a path
        Storage::shouldReceive('putFileAs')
            ->with(VoiceSampleFileManager::VOICE_SAMPLES_FOLDER, $fileMock, Mockery::any())
            ->once()
            ->andReturn('files/samples/test-uuid.mp3');

        // Execute
        $result = $fileManager->processSampleFile($fileMock);

        // Assert
        $this->assertTrue($result);
        $this->assertNotEmpty($fileManager->getFileName());
        $this->assertStringEndsWith('.mp3', $fileManager->getFileName());
        $this->assertEquals(126, $fileManager->getFileDuration()); 
    }

    public function test_process_sample_file_throws_exception_on_error()
    {
        // Mock getID3 to throw exception
        $getID3Mock = Mockery::mock(getID3::class);
        $getID3Mock->shouldReceive('analyze')
            ->once()
            ->andThrow(new \Exception('Failed to analyze file'));

        // Mock UploadedFile
        $fileMock = Mockery::mock(UploadedFile::class);
        $fileMock->shouldReceive('getClientOriginalExtension')
            ->once()
            ->andReturn('mp3');
        $fileMock->shouldReceive('getPathname')
            ->once()
            ->andReturn('/tmp/test.mp3');
        
        // Create instance with mocked getID3
        $fileManager = new VoiceSampleFileManager($getID3Mock);
        
        // Mock Storage::putFileAs to return a path
        Storage::shouldReceive('putFileAs')
            ->with(VoiceSampleFileManager::VOICE_SAMPLES_FOLDER, $fileMock, Mockery::any())
            ->once()
            ->andReturn('files/samples/test-uuid.mp3');

        // Expect exception
        $this->expectException(VoiceSampleFileManagerCouldNotProcessSample::class);
        $this->expectExceptionMessage('Failed to analyze file');

        // Execute
        $fileManager->processSampleFile($fileMock);
    }
}
