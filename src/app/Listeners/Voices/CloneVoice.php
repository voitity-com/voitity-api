<?php

namespace App\Listeners\Voices;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Classes\VoiceService\VoiceManager;
use App\Classes\VoiceService\VoiceService;
use App\Events\Voices\VoiceSampleAdded;
use App\Jobs\Voices\DeleteReplacedProviderVoice;
use App\Listeners\Concerns\RoutesToMediaQueue;
use App\Models\User;
use App\Models\Voice;
use App\Models\VoiceProviderRequest;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\ProfileConversationMessageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CloneVoice implements ShouldQueue
{
    use InteractsWithQueue, RoutesToMediaQueue;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The maximum number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * The VoiceManager instance.
     */
    protected VoiceManager $voiceManager;

    /**
     * Create the event listener.
     */
    public function __construct(VoiceManager $voiceManager)
    {
        $this->voiceManager = $voiceManager;
    }

    /**
     * Handle the event.
     */
    public function handle(VoiceSampleAdded $event): void
    {
        $voice = $event->voice;
        $voiceSample = $event->voiceSample;

        $providerRequest = VoiceProviderRequest::query()
            ->where('voice_id', $voice->id)
            ->where('voice_sample_id', $voiceSample->id)
            ->where('status', VoiceProviderRequest::STATUS_PENDING)
            ->latest('id')
            ->first();

        if (! $providerRequest instanceof VoiceProviderRequest) {
            Log::warning('Voice cloning skipped because no pending provider request exists.', [
                'voice_id' => $voice->id,
                'voice_sample_id' => $voiceSample->id,
            ]);

            return;
        }

        $usageKey = "voice-clone:provider-request:{$providerRequest->id}";
        $replacedProviderVoiceId = $voice->source_voice_id;
        $replacedProvider = $voice->source;

        Log::info('CloneVoice listener triggered', [
            'voice_id' => $voice->id,
            'voice_name' => $voice->name,
            'user_id' => $voice->user_id,
            'voice_sample_id' => $voiceSample->id,
        ]);

        try {
            // Create VoiceService instance using VoiceManager
            $voiceClient = $this->voiceManager->driver();
            $voiceService = new VoiceService($voice, $voiceClient);

            // Clone the voice using the voice sample
            $clonedVoice = $voiceService->cloneVoice($voiceSample);

            Log::info('Voice cloning successful', [
                'voice_id' => $voice->id,
                'voice_sample_id' => $voiceSample->id,
                'source' => $clonedVoice->source,
                'provider_voice_id' => $clonedVoice->getProviderVoiceId(),
                'status' => $clonedVoice->status,
            ]);

            if ($clonedVoice->isSuccessful()) {
                app(SubscriptionUsageRecorder::class)->finalize(
                    $usageKey,
                    [
                        'provider' => $clonedVoice->source,
                        'provider_voice_id' => $clonedVoice->getProviderVoiceId(),
                        'voice_sample_id' => $voiceSample->id,
                    ],
                    Voice::class,
                    (string) $voice->id,
                );

                if (
                    filled($replacedProviderVoiceId)
                    && filled($replacedProvider)
                    && $replacedProviderVoiceId !== $clonedVoice->getProviderVoiceId()
                ) {
                    DeleteReplacedProviderVoice::dispatch(
                        $voice->id,
                        (string) $replacedProvider,
                        (string) $replacedProviderVoiceId,
                    );
                }

                $this->notifyVoiceOwner($voice, 'voice_cloned_successfully', [
                    'provider' => $clonedVoice->source,
                ]);
                $freshVoice = $voice->fresh();

                if ($freshVoice instanceof Voice) {
                    app(ProfileConversationMessageService::class)->generateMissingAudiosForVoice($freshVoice);
                }
            } else {
                $this->notifyVoiceOwner($voice, 'voice_cloning_failed', [
                    'reason' => $clonedVoice->status,
                ]);
            }

        } catch (\Exception $e) {
            Log::error('Voice cloning failed during processing', [
                'voice_id' => $voice->id,
                'voice_sample_id' => $voiceSample->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to trigger the failed method
            throw $e;
        }

        Log::info('CloneVoice processing completed', [
            'voice_id' => $voice->id,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(VoiceSampleAdded $event, \Throwable $exception): void
    {
        $voice = $event->voice;
        $voiceSample = $event->voiceSample;

        Log::error('CloneVoice listener failed', [
            'voice_id' => $voice->id,
            'voice_sample_id' => $voiceSample->id,
            'user_id' => $voice->user_id,
            'error' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'attempts' => $this->attempts(),
        ]);

        $providerRequest = VoiceProviderRequest::query()
            ->where('voice_id', $voice->id)
            ->where('voice_sample_id', $voiceSample->id)
            ->latest('id')
            ->first();

        if ($providerRequest) {
            app(SubscriptionUsageRecorder::class)->release(
                "voice-clone:provider-request:{$providerRequest->id}"
            );
            $providerRequest->forceFill([
                'status' => VoiceProviderRequest::STATUS_FAILED,
                'response' => json_encode([
                    'error' => $exception->getMessage(),
                    'exception' => get_class($exception),
                ]),
                'processed_at' => now(),
            ])
                ->save();
        }

        $this->notifyVoiceOwner($voice, 'voice_cloning_failed', [
            'reason' => $exception->getMessage(),
        ]);
        app(NotificationDispatcher::class)->sendToAdmins('external_integration_error', [
            'service' => 'Voice provider',
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notifyVoiceOwner(Voice $voice, string $key, array $data = []): void
    {
        $voice->loadMissing('profile.user', 'user');
        $owner = $voice->profile?->user ?: $voice->user;

        if (! $owner instanceof User) {
            return;
        }

        app(NotificationDispatcher::class)->send($owner, $key, [
            'profile' => $voice->profile?->name ?: ($voice->name ?: "Voice {$voice->id}"),
            'profile_id' => $voice->profile_id,
            'voice_id' => $voice->id,
            'action_url' => $voice->profile_id ? "/dashboard/profiles/{$voice->profile_id}/voice" : '/dashboard/profiles',
            ...$data,
        ]);
    }
}
