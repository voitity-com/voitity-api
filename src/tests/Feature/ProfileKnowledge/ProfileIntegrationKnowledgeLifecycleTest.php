<?php

declare(strict_types=1);

namespace Tests\Feature\ProfileKnowledge;

use App\Jobs\ProfileKnowledge\IndexProfileKnowledge;
use App\Models\Profile;
use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use App\Models\ProfileKnowledgeChunk;
use App\Models\User;
use App\Services\ProfileKnowledge\ProfileIntegrationKnowledgeLifecycle;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileIntegrationKnowledgeLifecycleTest extends TestCase
{
    #[Test]
    public function deselection_deactivates_chunk_immediately_and_schedules_reindex(): void
    {
        [$profile, $integration, $media] = $this->integrationMedia('tiktok', false);
        $chunk = $this->chunk($profile, $media);

        app(ProfileIntegrationKnowledgeLifecycle::class)->selectionChanged($integration);

        $this->assertFalse($chunk->fresh()->active);
        Queue::assertPushed(IndexProfileKnowledge::class, fn (IndexProfileKnowledge $job): bool => $job->profileId === $profile->id);
    }

    #[Test]
    public function disconnect_cleanup_deletes_chunks_for_every_supported_provider(): void
    {
        foreach (['instagram', 'tiktok', 'youtube', 'onlyfans', 'other'] as $provider) {
            [$profile, , $media] = $this->integrationMedia($provider, true);
            $this->chunk($profile, $media);

            app(ProfileIntegrationKnowledgeLifecycle::class)->forgetMedia($profile->id, [$media->id], $provider);

            $this->assertDatabaseMissing('profile_knowledge_chunks', [
                'profile_id' => $profile->id,
                'source_type' => 'integration_media',
                'source_id' => (string) $media->id,
            ]);
        }
    }

    /** @return array{Profile, ProfileIntegration, ProfileIntegrationMedia} */
    private function integrationMedia(string $provider, bool $selected): array
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create();
        $integration = ProfileIntegration::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'provider' => $provider,
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ]);
        $media = ProfileIntegrationMedia::query()->create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $profile->id,
            'provider' => $provider,
            'provider_media_id' => "{$provider}-1",
            'media_type' => 'IMAGE',
            'selected' => $selected,
        ]);

        return [$profile, $integration, $media];
    }

    private function chunk(Profile $profile, ProfileIntegrationMedia $media): ProfileKnowledgeChunk
    {
        return ProfileKnowledgeChunk::query()->create([
            'profile_id' => $profile->id,
            'chunk_key' => "integration.media.{$media->id}",
            'source_type' => 'integration_media',
            'source_id' => (string) $media->id,
            'content' => 'Integration test content',
            'content_hash' => hash('sha256', (string) $media->id),
            'embedding_model' => 'test',
            'embedding_dimensions' => 3,
            'active' => true,
        ]);
    }
}
