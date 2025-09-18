<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Classes\VoiceSampleFileManager;
use App\Models\User;
use App\Models\Voice;
use App\Models\VoiceSample;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery;

class VoiceSampleControllerTest extends TestAPI
{
    const ENDPOINT_VOICESAMPLE = '/api/voice/voice-id/sample';

    public function setUp(): void
    {
        parent::setUp();
        
        // Use fake storage to prevent actual file operations
        Storage::fake('local');
        
        /*
         * APPROACH 1 (Current): Mock VoiceSampleFileManager
         * - Prevents actual file creation
         * - Faster test execution
         * - Tests controller logic without file I/O
         * - Recommended for controller tests
         * 
         * APPROACH 2 (Alternative): Real file operations with cleanup
         * - Replace Storage::fake('local') with Storage::fake()
         * - Remove VoiceSampleFileManager mocking in individual tests
         * - Files will be automatically cleaned up by Storage::fake()
         * - Better for integration testing but slower
         */
    }

    protected function tearDown(): void
    {
        // Clean up Mockery mocks
        Mockery::close();
        parent::tearDown();
    }

    public function test_store_fails_with_invalid_fields()
    {
        $voice = Voice::factory()->create(['user_id' => 1]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken())
            ->postJson(str_replace('voice-id', (string)$voice->id, self::ENDPOINT_VOICESAMPLE), [
                'file' => '', // empty
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file']);
    }

    public function test_unauthorized_user_can_not_create_profile()
    {
        $response = $this->withHeader('Authorization', 'Bearer ' . $this->faker->word())
            ->postJson(str_replace('voice-id', '1', self::ENDPOINT_VOICESAMPLE), []);

        $response->assertStatus(401);
        $response->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_user_can_not_create_voice_sample_if_he_is_not_voice_owner()
    {
        // Mock the VoiceSampleFileManager (even though this test should fail before using it)
        $mockFileManager = Mockery::mock(VoiceSampleFileManager::class);
        $this->app->instance(VoiceSampleFileManager::class, $mockFileManager);

        // Create a user with id 2 first
        $user = User::factory()->create();
        $voice = Voice::factory()->create(['user_id' => $user->id]);
        $data = [
            'file' => UploadedFile::fake()->create('sample.mp3', 100, 'audio/mpeg')
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken())
            ->postJson(str_replace('voice-id', (string)$voice->id, self::ENDPOINT_VOICESAMPLE), $data);
            
        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Voice not found.');
    }

    public function test_user_can_create_voice_sample()
    {
        // Mock the VoiceSampleFileManager to avoid actual file operations
        $mockFileManager = Mockery::mock(VoiceSampleFileManager::class);
        $mockFileManager->shouldReceive('processSampleFile')
            ->once()
            ->andReturn(true);
        $mockFileManager->shouldReceive('getFileName')
            ->once()
            ->andReturn('test-uuid-123.mp3');
        $mockFileManager->shouldReceive('getFileDuration')
            ->once()
            ->andReturn(120);

        // Bind the mock to the service container
        $this->app->instance(VoiceSampleFileManager::class, $mockFileManager);

        $voice = Voice::factory()->create(['user_id' => 1]);
        $data = [
            'file' => UploadedFile::fake()->create('sample.mp3', 100, 'audio/mpeg')
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $this->getToken())
            ->postJson(str_replace('voice-id', (string)$voice->id, self::ENDPOINT_VOICESAMPLE), $data);
            
        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Voice sample created successfully.');
        $response_content = json_decode($response->getContent());

        $new_sample = VoiceSample::find($response_content->data->id);
        $this->assertEquals('mp3', pathinfo($new_sample->file, PATHINFO_EXTENSION));
        $this->assertTrue((boolean)$new_sample->active);
    }
}
