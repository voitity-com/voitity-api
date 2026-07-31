<?php

declare(strict_types=1);

namespace Tests\Unit\Listeners\Voices;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Classes\VoiceService\VoiceClient;
use App\Classes\VoiceService\VoiceClientClonedVoice;
use App\Classes\VoiceService\VoiceManager;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionUsageType;
use App\Events\Voices\VoiceSampleAdded;
use App\Jobs\Voices\DeleteReplacedProviderVoice;
use App\Listeners\Voices\CloneVoice;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\SubscriptionUse;
use App\Models\User;
use App\Models\Voice;
use App\Models\VoiceProviderRequest;
use App\Models\VoiceSample;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\ProfileConversationMessageService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CloneVoiceTest extends TestCase
{
    public function test_successful_reclone_queues_cleanup_for_the_replaced_provider_voice(): void
    {
        Event::fake();
        Queue::fake();
        $user = User::factory()->create();
        $voice = Voice::factory()->create([
            'user_id' => $user->id,
            'source' => 'elevenlabs',
            'source_voice_id' => 'old-provider-voice',
        ]);
        $voiceSample = VoiceSample::factory()->create([
            'voice_id' => $voice->id,
            'duration' => 10,
            'active' => true,
        ]);
        VoiceProviderRequest::factory()->pending()->create([
            'voice_id' => $voice->id,
            'voice_sample_id' => $voiceSample->id,
            'source' => '',
            'source_voice_id' => null,
            'request_url' => '',
        ]);
        $client = Mockery::mock(VoiceClient::class);
        $client->shouldReceive('cloneVoice')->once()->andReturn(new VoiceClientClonedVoice(
            'elevenlabs',
            'new-provider-voice',
            'completed',
            [],
            'https://api.elevenlabs.io/v1/voices/add',
        ));
        $manager = Mockery::mock(VoiceManager::class);
        $manager->shouldReceive('driver')->once()->andReturn($client);
        $notifications = Mockery::mock(NotificationDispatcher::class);
        $notifications->shouldReceive('send')->once();
        $messages = Mockery::mock(ProfileConversationMessageService::class);
        $messages->shouldReceive('generateMissingAudiosForVoice')->once();
        $usage = Mockery::mock(SubscriptionUsageRecorder::class);
        $usage->shouldReceive('finalize')
            ->once()
            ->withArgs(fn (
                string $key,
                array $metadata,
                string $sourceType,
                string $sourceId
            ): bool => str_starts_with($key, 'voice-clone:provider-request:')
                && $metadata['provider_voice_id'] === 'new-provider-voice'
                && $sourceType === Voice::class
                && $sourceId === (string) $voice->id);
        $this->app->instance(NotificationDispatcher::class, $notifications);
        $this->app->instance(ProfileConversationMessageService::class, $messages);
        $this->app->instance(SubscriptionUsageRecorder::class, $usage);

        (new CloneVoice($manager))->handle(new VoiceSampleAdded($voice, $voiceSample));

        $this->assertSame('new-provider-voice', $voice->fresh()->source_voice_id);
        Queue::assertPushed(
            DeleteReplacedProviderVoice::class,
            fn (DeleteReplacedProviderVoice $job): bool => $job->voiceId === $voice->id
                && $job->provider === 'elevenlabs'
                && $job->providerVoiceId === 'old-provider-voice'
        );
    }

    public function test_failed_clone_releases_reserved_usage_and_marks_provider_request_failed(): void
    {
        $user = User::factory()->create();
        $subscription = $this->createActiveSubscriptionFor($user);
        $voice = Voice::factory()->create([
            'user_id' => $user->id,
            'source_voice_id' => null,
        ]);
        $voiceSample = VoiceSample::factory()->create([
            'voice_id' => $voice->id,
            'duration' => 10,
            'active' => true,
        ]);
        $providerRequest = VoiceProviderRequest::factory()->pending()->create([
            'voice_id' => $voice->id,
            'voice_sample_id' => $voiceSample->id,
            'source' => '',
            'source_voice_id' => null,
            'request_url' => '',
        ]);
        $recorder = new SubscriptionUsageRecorder;

        $recorder->reserve(
            userId: $user->id,
            usageType: SubscriptionUsageType::VoiceCloned,
            amounts: ['voice_clones' => 1],
            idempotencyKey: "voice-clone:provider-request:{$providerRequest->id}",
            profileId: $voice->profile_id,
            sourceType: Voice::class,
            sourceId: (string) $voice->id,
        );

        $this->assertSame(0, (int) $subscription->limit()->firstOrFail()->voice_clones_remaining);

        $listener = new CloneVoice(app(\App\Classes\VoiceService\VoiceManager::class));

        $listener->failed(
            new VoiceSampleAdded($voice, $voiceSample),
            new RuntimeException('Provider rejected sample')
        );

        $this->assertSame(
            SubscriptionUse::STATUS_RELEASED,
            SubscriptionUse::where(
                'idempotency_key',
                "voice-clone:provider-request:{$providerRequest->id}"
            )->value('status')
        );
        $this->assertSame(1, (int) $subscription->limit()->firstOrFail()->voice_clones_remaining);

        $providerRequest->refresh();
        $this->assertSame(VoiceProviderRequest::STATUS_FAILED, $providerRequest->status);
        $this->assertStringContainsString('Provider rejected sample', (string) $providerRequest->response);
    }

    private function createActiveSubscriptionFor(User $user): Subscription
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
            'tts_characters_remaining' => 10000,
            'chat_messages_remaining' => 1000,
            'credits_remaining' => 1000,
        ]);

        return $subscription;
    }
}
