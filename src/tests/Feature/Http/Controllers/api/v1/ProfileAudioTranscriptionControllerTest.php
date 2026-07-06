<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Classes\ChatAIService\ChatAIClient;
use App\Classes\ChatAIService\ChatAITextFromAudio;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Mockery;

class ProfileAudioTranscriptionControllerTest extends TestAPI
{
    private const ENDPOINT = '/api/profile';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_user_can_transcribe_audio_for_owned_profile(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($user);
        $token = $user->createToken('test-token', ['profile:transcribe'])->plainTextToken;

        $this->mockTranscriptionClient('Descripcion grabada desde audio.');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT.'/'.$profile->id.'/transcriptions/audio', [
                'audio' => $this->validAudioUpload(),
                'field' => 'description',
                'language' => 'es',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Audio transcribed successfully.');
        $response->assertJsonPath('data.text', 'Descripcion grabada desde audio.');
        $response->assertJsonPath('data.field', 'description');
        $response->assertJsonPath('data.limits.max', 500);
        $response->assertJsonPath('data.limits.min', 1);
        $response->assertJsonPath('data.exceeds_limit', false);
        $response->assertJsonPath('data.below_minimum', false);
    }

    public function test_admin_can_transcribe_audio_for_foreign_profile(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($owner);
        $token = $admin->createToken('test-token', ['profile:transcribe'])->plainTextToken;

        $this->mockTranscriptionClient('Personalidad grabada por admin.');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT.'/'.$profile->id.'/transcriptions/audio', [
                'audio' => $this->validAudioUpload(),
                'field' => 'personality',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.text', 'Personalidad grabada por admin.');
        $response->assertJsonPath('data.limits.max', 200);
    }

    public function test_user_without_profile_transcribe_ability_can_not_transcribe_audio(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($user);
        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldNotReceive('getTextFromAudio');
        $this->instance(ChatAIClient::class, $chatAiClient);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT.'/'.$profile->id.'/transcriptions/audio', [
                'audio' => $this->validAudioUpload(),
            ]);

        $response->assertStatus(403);
    }

    public function test_user_can_not_transcribe_audio_for_foreign_profile(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $owner = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($owner);
        $token = $user->createToken('test-token', ['profile:transcribe'])->plainTextToken;

        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldNotReceive('getTextFromAudio');
        $this->instance(ChatAIClient::class, $chatAiClient);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT.'/'.$profile->id.'/transcriptions/audio', [
                'audio' => $this->validAudioUpload(),
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Profile not found.');
    }

    public function test_transcribe_audio_validates_audio_field(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($user);
        $token = $user->createToken('test-token', ['profile:transcribe'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT.'/'.$profile->id.'/transcriptions/audio', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['audio']);
    }

    public function test_transcribe_audio_validates_field(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($user);
        $token = $user->createToken('test-token', ['profile:transcribe'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT.'/'.$profile->id.'/transcriptions/audio', [
                'audio' => $this->validAudioUpload(),
                'field' => 'name',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['field']);
    }

    public function test_transcribe_audio_returns_error_when_transcription_fails(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($user);
        $token = $user->createToken('test-token', ['profile:transcribe'])->plainTextToken;

        $this->mockTranscriptionClient('', 'failed');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT.'/'.$profile->id.'/transcriptions/audio', [
                'audio' => $this->validAudioUpload(),
            ]);

        $response->assertStatus(502);
        $response->assertJsonPath('message', 'Audio transcription failed.');
        $response->assertJsonPath('data.status', 'failed');
    }

    public function test_transcribe_audio_marks_text_that_exceeds_field_limit(): void
    {
        $user = User::factory()->create(['role' => 'user']);
        $profile = $this->createProfileFor($user);
        $token = $user->createToken('test-token', ['profile:transcribe'])->plainTextToken;
        $text = str_repeat('a', 201);

        $this->mockTranscriptionClient($text);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT.'/'.$profile->id.'/transcriptions/audio', [
                'audio' => $this->validAudioUpload(),
                'field' => 'personality',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.characters', 201);
        $response->assertJsonPath('data.limits.max', 200);
        $response->assertJsonPath('data.exceeds_limit', true);
    }

    private function createProfileFor(User $user): Profile
    {
        return Profile::create([
            'user_id' => $user->id,
            'name' => $this->faker->name,
            'description' => $this->faker->text(200),
            'genre' => 'male',
            'personality' => $this->faker->text(100),
        ]);
    }

    private function validAudioUpload(): UploadedFile
    {
        return UploadedFile::fake()->create('recording.webm', 128, 'audio/webm');
    }

    private function mockTranscriptionClient(string $text, string $status = 'success'): void
    {
        $chatAiClient = Mockery::mock(ChatAIClient::class);
        $chatAiClient->shouldReceive('getTextFromAudio')
            ->once()
            ->withArgs(function (string $audioPath): bool {
                return file_exists($audioPath) && str_ends_with($audioPath, '.webm');
            })
            ->andReturn(new ChatAITextFromAudio(
                source: 'openai',
                audioPath: '/tmp/audio.webm',
                text: $text,
                status: $status,
                confidence: $status === 'success' ? 0.9 : null,
                detectedLanguage: $status === 'success' ? 'es' : null,
                duration: $status === 'success' ? 3.5 : null
            ));

        $this->instance(ChatAIClient::class, $chatAiClient);
    }
}
