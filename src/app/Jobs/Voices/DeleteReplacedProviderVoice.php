<?php

namespace App\Jobs\Voices;

use App\Classes\VoiceService\DeletesProviderVoices;
use App\Classes\VoiceService\VoiceManager;
use App\Models\Voice;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DeleteReplacedProviderVoice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly int $voiceId,
        public readonly string $provider,
        public readonly string $providerVoiceId,
    ) {}

    public function handle(VoiceManager $voiceManager): void
    {
        $voice = Voice::find($this->voiceId);

        if (! $voice instanceof Voice) {
            Log::info('Replaced provider voice cleanup skipped because local voice no longer exists.', [
                'provider' => $this->provider,
                'provider_voice_id' => $this->providerVoiceId,
                'voice_id' => $this->voiceId,
            ]);

            return;
        }

        $client = $voiceManager->driver($this->provider);

        if (! $client instanceof DeletesProviderVoices) {
            throw new RuntimeException("Voice provider [{$this->provider}] does not support voice deletion.");
        }

        $client->deleteProviderVoice($voice, $this->providerVoiceId);

        Log::info('Replaced provider voice deleted.', [
            'provider' => $this->provider,
            'provider_voice_id' => $this->providerVoiceId,
            'voice_id' => $this->voiceId,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Replaced provider voice cleanup failed permanently.', [
            'error' => $exception->getMessage(),
            'provider' => $this->provider,
            'provider_voice_id' => $this->providerVoiceId,
            'voice_id' => $this->voiceId,
        ]);

        app(NotificationDispatcher::class)->sendToAdmins('external_integration_error', [
            'service' => 'Voice provider cleanup',
            'message' => $exception->getMessage(),
        ]);
    }
}
