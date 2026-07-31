<?php

namespace Tests\Unit\Jobs\Voices;

use App\Classes\VoiceService\DeletesProviderVoices;
use App\Classes\VoiceService\VoiceClient;
use App\Classes\VoiceService\VoiceManager;
use App\Jobs\Voices\DeleteReplacedProviderVoice;
use App\Models\User;
use App\Models\Voice;
use Mockery;
use Tests\TestCase;

class DeleteReplacedProviderVoiceTest extends TestCase
{
    public function test_job_deletes_the_replaced_voice_with_the_original_provider(): void
    {
        $user = User::factory()->create();
        $voice = Voice::factory()->create(['user_id' => $user->id]);
        $client = Mockery::mock(VoiceClient::class, DeletesProviderVoices::class);
        $client->shouldReceive('deleteProviderVoice')
            ->once()
            ->withArgs(fn (Voice $candidate, string $providerVoiceId): bool => $candidate->is($voice)
                && $providerVoiceId === 'old-provider-voice')
            ->andReturnTrue();
        $manager = Mockery::mock(VoiceManager::class);
        $manager->shouldReceive('driver')->once()->with('elevenlabs')->andReturn($client);

        (new DeleteReplacedProviderVoice(
            $voice->id,
            'elevenlabs',
            'old-provider-voice',
        ))->handle($manager);
    }

    public function test_job_is_idempotent_when_the_local_voice_was_deleted(): void
    {
        $manager = Mockery::mock(VoiceManager::class);
        $manager->shouldNotReceive('driver');

        (new DeleteReplacedProviderVoice(
            PHP_INT_MAX,
            'elevenlabs',
            'old-provider-voice',
        ))->handle($manager);

        $this->assertTrue(true);
    }
}
