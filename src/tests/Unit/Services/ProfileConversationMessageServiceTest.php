<?php

namespace Tests\Unit\Services;

use App\Classes\Subscriptions\SubscriptionEntitlementService;
use App\Classes\VoiceService\VoiceClient;
use App\Classes\VoiceService\VoiceClientAddedSample;
use App\Classes\VoiceService\VoiceClientClonedVoice;
use App\Classes\VoiceService\VoiceClientGeneratedAudio;
use App\Classes\VoiceService\VoiceManager;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Voice;
use App\Models\VoiceSample;
use App\Services\ProfileConversationMessageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProfileConversationMessageServiceTest extends TestCase
{
    private FakeConversationVoiceClient $voiceClient;

    private ProfileConversationMessageService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake();

        $this->voiceClient = new FakeConversationVoiceClient;
        $voiceManager = Mockery::mock(VoiceManager::class);
        $voiceManager->shouldReceive('driver')->andReturn($this->voiceClient);
        $this->app->instance(VoiceManager::class, $voiceManager);

        $subscription = new Subscription([
            'active' => true,
            'plan' => 'starter',
            'renews_at' => now()->addMonth(),
            'started_at' => now(),
        ]);
        $entitlements = Mockery::mock(SubscriptionEntitlementService::class);
        $entitlements->shouldReceive('assertCanUse')->andReturn($subscription);
        $this->app->instance(SubscriptionEntitlementService::class, $entitlements);

        $this->service = new ProfileConversationMessageService($voiceManager);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_resolves_default_initial_message_and_disabled_fallback(): void
    {
        $profile = Profile::factory()->create(['name' => 'Valeria Rios']);

        $messages = $this->service->resolvedMessages($profile);

        $this->assertTrue($messages['initial']['enabled']);
        $this->assertSame(
            'Hola, soy Valeria Rios. Pregúntame sobre mi trabajo, mis proyectos o lo que quieres conocer de mí.',
            $messages['initial']['text']
        );
        $this->assertNull($messages['initial']['audio_url']);
        $this->assertFalse($messages['fallback_no_answer']['enabled']);
        $this->assertNull($messages['fallback_no_answer']['text']);
    }

    public function test_saves_text_before_voice_exists_without_generating_audio(): void
    {
        $profile = Profile::factory()->create();

        $messages = $this->service->updateMessages($profile, [
            'initial' => ['text' => 'Bienvenido a mi perfil digital.'],
            'fallback_no_answer' => ['text' => 'Todavía no tengo ese dato.'],
        ]);

        $this->assertSame('Bienvenido a mi perfil digital.', $messages['initial']['text']);
        $this->assertSame('Todavía no tengo ese dato.', $messages['fallback_no_answer']['text']);
        $this->assertNull($messages['initial']['audio_url']);
        $this->assertNull($messages['fallback_no_answer']['audio_url']);
        $this->assertSame([], $this->voiceClient->generatedTexts);
    }

    public function test_generates_audio_automatically_when_active_cloned_voice_exists(): void
    {
        [$profile] = $this->createProfileWithClonedVoice();

        $messages = $this->service->updateMessages($profile, [
            'initial' => ['text' => 'Hola, este es mi mensaje inicial personalizado.'],
        ]);

        $this->assertSame(
            'Hola, este es mi mensaje inicial personalizado.',
            $this->voiceClient->generatedTexts[0] ?? null
        );
        $this->assertSame('generated', $messages['initial']['audio_source']);
        $this->assertSame('ready', $messages['initial']['status']);
        $this->assertStringStartsWith('https://audio.test/', $messages['initial']['audio_url']);
    }

    public function test_generates_missing_audios_when_voice_is_cloned_after_text_is_saved(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->create(['user_id' => $user->id]);
        $this->service->updateMessages($profile, [
            'fallback_no_answer' => ['text' => 'No tengo esa respuesta todavía.'],
        ]);
        $voice = Voice::factory()->create([
            'active' => true,
            'profile_id' => $profile->id,
            'source' => 'elevenlabs',
            'source_voice_id' => 'voice-123',
            'user_id' => $user->id,
        ]);

        $this->service->generateMissingAudiosForVoice($voice->refresh());

        $messages = $this->service->resolvedMessages($profile->fresh(['conversationMessages', 'voices']));

        $this->assertCount(2, $this->voiceClient->generatedTexts);
        $this->assertSame('generated', $messages['initial']['audio_source']);
        $this->assertSame('generated', $messages['fallback_no_answer']['audio_source']);
    }

    public function test_uploaded_audio_is_recorded_and_returned_without_a_cloned_voice(): void
    {
        Storage::fake('public');
        $profile = Profile::factory()->create();
        $audio = UploadedFile::fake()->create('intro.webm', 128, 'audio/webm');

        $message = $this->service->uploadAudio($profile, 'initial', $audio);
        $resolved = $this->service->resolvedMessage($profile->fresh(['conversationMessages', 'voices']), 'initial');

        $this->assertSame('recorded', $message->audio_source);
        $this->assertSame('recorded', $resolved['audio_source']);
        $this->assertNotNull($resolved['audio_url']);
        Storage::disk('public')->assertExists($message->audio_path);
    }

    /**
     * @return array{0: Profile, 1: Voice}
     */
    private function createProfileWithClonedVoice(): array
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->create(['user_id' => $user->id]);
        $voice = Voice::factory()->create([
            'active' => true,
            'profile_id' => $profile->id,
            'source' => 'elevenlabs',
            'source_voice_id' => 'voice-123',
            'user_id' => $user->id,
        ]);

        return [$profile, $voice];
    }
}

class FakeConversationVoiceClient implements VoiceClient
{
    /**
     * @var list<string>
     */
    public array $generatedTexts = [];

    public function cloneVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientClonedVoice
    {
        throw new RuntimeException('Clone voice is not used in this test.');
    }

    public function addVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientAddedSample
    {
        throw new RuntimeException('Add voice is not used in this test.');
    }

    public function generateAudio(Voice $voice, string $text): VoiceClientGeneratedAudio
    {
        $this->generatedTexts[] = $text;

        return new VoiceClientGeneratedAudio(
            voice: $voice,
            text: $text,
            audioUrl: 'https://audio.test/'.sha1($text).'.mp3',
            audioFormat: 'mp3',
            duration: 1.5,
            status: 'completed',
            metadata: ['fake' => true]
        );
    }
}
