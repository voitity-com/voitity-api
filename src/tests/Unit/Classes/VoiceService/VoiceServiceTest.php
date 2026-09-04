<?php

namespace Tests\Unit\Classes\VoiceService;

use App\Classes\VoiceService\VoiceClient;
use App\Classes\VoiceService\VoiceClientAddedSample;
use App\Classes\VoiceService\VoiceClientClonedVoice;
use App\Classes\VoiceService\VoiceClientGeneratedAudio;
use App\Classes\VoiceService\VoiceService;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Subscriptions\SubscriptionEntitlementException;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\SubscriptionUse;
use App\Models\User;
use App\Models\Voice;
use App\Models\VoiceProviderRequest;
use App\Models\VoiceSample;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VoiceServiceTest extends TestCase
{
    #[Test]
    public function it_stores_provider_voice_id_on_clone_request_for_auditability(): void
    {
        $user = User::factory()->create();
        $voice = Voice::factory()->create([
            'user_id' => $user->id,
            'source' => null,
            'source_voice_id' => null,
        ]);
        $sample = VoiceSample::factory()->create(['voice_id' => $voice->id]);
        $request = VoiceProviderRequest::factory()->pending()->create([
            'voice_id' => $voice->id,
            'voice_sample_id' => $sample->id,
            'source' => '',
            'source_voice_id' => null,
            'request_url' => '',
        ]);
        $client = new class implements VoiceClient
        {
            public function cloneVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientClonedVoice
            {
                return new VoiceClientClonedVoice(
                    source: 'elevenlabs',
                    providerVoiceId: 'provider-voice-123',
                    status: 'completed',
                    response: ['voice_id' => 'provider-voice-123'],
                    requestUrl: 'https://api.elevenlabs.io/v1/voices/add'
                );
            }

            public function addVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientAddedSample
            {
                return new VoiceClientAddedSample('elevenlabs');
            }

            public function generateAudio(Voice $voice, string $text): VoiceClientGeneratedAudio
            {
                return new VoiceClientGeneratedAudio($voice, $text);
            }
        };

        (new VoiceService($voice, $client))->cloneVoice($sample);

        $request->refresh();
        $voice->refresh();

        $this->assertSame(VoiceProviderRequest::STATUS_COMPLETED, $request->status);
        $this->assertSame('elevenlabs', $request->source);
        $this->assertSame('provider-voice-123', $request->source_voice_id);
        $this->assertSame('provider-voice-123', $voice->source_voice_id);
        $this->assertSame('elevenlabs', $voice->source);
    }

    #[Test]
    public function it_records_each_successful_generation_even_when_the_text_is_identical(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create();
        $subscription = $this->createActiveSubscriptionFor($user, 20);
        $voice = Voice::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source' => 'elevenlabs',
        ]);
        $client = $this->successfulAudioClient();
        $service = new VoiceService($voice, $client);

        $service->generateAudio('Hola');
        $service->generateAudio('Hola');

        $this->assertSame(2, $client->calls);
        $this->assertSame(12, $subscription->limit()->firstOrFail()->tts_characters_remaining);
        $this->assertSame(2, SubscriptionUse::where('status', SubscriptionUse::STATUS_FINALIZED)->count());
        $this->assertSame(8, SubscriptionUse::sum('tts_characters_used'));
    }

    #[Test]
    public function it_rejects_generation_before_calling_the_provider_when_tts_quota_is_exhausted(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create();
        $this->createActiveSubscriptionFor($user, 3);
        $voice = Voice::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source' => 'elevenlabs',
        ]);
        $client = $this->successfulAudioClient();

        try {
            (new VoiceService($voice, $client))->generateAudio('Hola');
            $this->fail('Expected TTS quota enforcement to reject the request.');
        } catch (SubscriptionEntitlementException $exception) {
            $this->assertSame('PURCHASED_CREDITS_REQUIRED', $exception->errorCode());
        }

        $this->assertSame(0, $client->calls);
        $this->assertSame(0, SubscriptionUse::count());
    }

    #[Test]
    public function it_releases_reserved_tts_quota_when_the_provider_fails(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create();
        $subscription = $this->createActiveSubscriptionFor($user, 20);
        $voice = Voice::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source' => 'elevenlabs',
        ]);
        $client = new class implements VoiceClient
        {
            public function cloneVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientClonedVoice
            {
                throw new \RuntimeException('Not used.');
            }

            public function addVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientAddedSample
            {
                throw new \RuntimeException('Not used.');
            }

            public function generateAudio(Voice $voice, string $text): VoiceClientGeneratedAudio
            {
                throw new \RuntimeException('Provider unavailable.');
            }
        };

        try {
            (new VoiceService($voice, $client))->generateAudio('Hola');
            $this->fail('Expected the provider exception.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Provider unavailable.', $exception->getMessage());
        }

        $this->assertSame(20, $subscription->limit()->firstOrFail()->tts_characters_remaining);
        $this->assertSame(SubscriptionUse::STATUS_RELEASED, SubscriptionUse::firstOrFail()->status);
    }

    #[Test]
    public function it_releases_reserved_tts_quota_when_the_provider_returns_a_failed_result(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create();
        $subscription = $this->createActiveSubscriptionFor($user, 20);
        $voice = Voice::factory()->create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source' => 'elevenlabs',
        ]);
        $client = new class implements VoiceClient
        {
            public function cloneVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientClonedVoice
            {
                throw new \RuntimeException('Not used.');
            }

            public function addVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientAddedSample
            {
                throw new \RuntimeException('Not used.');
            }

            public function generateAudio(Voice $voice, string $text): VoiceClientGeneratedAudio
            {
                return new VoiceClientGeneratedAudio(
                    voice: $voice,
                    text: $text,
                    status: 'failed',
                    metadata: ['error' => 'Audio storage failed.'],
                );
            }
        };

        $generatedAudio = (new VoiceService($voice, $client))->generateAudio('Hola');

        $this->assertTrue($generatedAudio->isFailed());
        $this->assertSame(20, $subscription->limit()->firstOrFail()->tts_characters_remaining);
        $this->assertSame(SubscriptionUse::STATUS_RELEASED, SubscriptionUse::firstOrFail()->status);
    }

    private function createActiveSubscriptionFor(User $user, int $ttsCharacters): Subscription
    {
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan' => SubscriptionPlan::Starter,
            'started_at' => now()->subDay(),
            'renews_at' => now()->addMonth(),
            'status' => SubscriptionStatus::First,
            'active' => true,
        ]);

        SubscriptionLimit::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'period_started_at' => $subscription->started_at,
            'period_renews_at' => $subscription->renews_at,
            'profiles_remaining' => 1,
            'avatar_images_remaining' => 1,
            'avatar_video_seconds_remaining' => 5,
            'voice_clones_remaining' => 1,
            'tts_characters_remaining' => $ttsCharacters,
            'chat_messages_remaining' => 1000,
            'incoming_audio_messages_remaining' => 500,
            'incoming_audio_seconds_remaining' => 15000,
            'credits_remaining' => 1000,
        ]);

        return $subscription;
    }

    private function successfulAudioClient(): VoiceClient
    {
        return new class implements VoiceClient
        {
            public int $calls = 0;

            public function cloneVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientClonedVoice
            {
                throw new \RuntimeException('Not used.');
            }

            public function addVoice(Voice $voice, VoiceSample $voiceSample): VoiceClientAddedSample
            {
                throw new \RuntimeException('Not used.');
            }

            public function generateAudio(Voice $voice, string $text): VoiceClientGeneratedAudio
            {
                $this->calls++;

                return new VoiceClientGeneratedAudio(
                    voice: $voice,
                    text: $text,
                    audioUrl: 'https://example.com/audio.mp3',
                    status: 'completed',
                );
            }
        };
    }
}
