<?php

declare(strict_types=1);

namespace Tests\Feature\ProfileKnowledge;

use App\Classes\EmbeddingService\EmbeddingClient;
use App\Classes\ProfileKnowledge\ProfileDataSynchronizer;
use App\Enums\ProfileFactVisibility;
use App\Enums\ProfileSourceStatus;
use App\Jobs\ProfileKnowledge\IndexProfileKnowledge;
use App\Jobs\ProfileKnowledge\SynchronizeProfileSource;
use App\Models\Profile;
use App\Models\ProfileFact;
use App\Models\ProfileKnowledgeChunk;
use App\Models\ProfileSource;
use App\Models\ProfileSourceItem;
use App\Models\User;
use App\Services\ProfileKnowledge\ProfileKnowledgeIndexer;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeEmbeddingClient;
use Tests\TestCase;

class ProfileSourceLifecycleTest extends TestCase
{
    #[Test]
    public function queued_source_is_approved_synchronized_and_indexed(): void
    {
        config()->set('ai-knowledge.embedding.dimensions', 3);
        config()->set('ai-knowledge.embedding.drivers.openai.dimensions', 3);
        $this->app->instance(EmbeddingClient::class, new FakeEmbeddingClient(fn (): array => [1.0, 0.0, 0.0]));
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create(['data' => ['manual' => 'keep']]);
        $source = ProfileSource::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'type' => 'cv',
            'name' => 'Queued source',
            'status' => ProfileSourceStatus::PendingSync,
            'extracted_text' => 'The QA beacon is ORQUIDEA-41.',
        ]);
        $item = ProfileSourceItem::query()->create([
            'profile_source_id' => $source->id,
            'profile_id' => $profile->id,
            'type' => 'summary',
            'title' => 'Summary',
            'content' => 'The QA beacon is ORQUIDEA-41.',
        ]);
        ProfileFact::query()->create([
            'profile_id' => $profile->id,
            'profile_source_id' => $source->id,
            'profile_source_item_id' => $item->id,
            'category' => 'summary',
            'text' => 'The QA beacon is ORQUIDEA-41.',
            'visibility' => ProfileFactVisibility::Public,
        ]);

        $job = new SynchronizeProfileSource((int) $source->id);
        $job->handle(
            app(ProfileDataSynchronizer::class),
            app(ProfileKnowledgeIndexer::class),
        );

        $source->refresh();
        $this->assertSame(ProfileSourceStatus::Indexed, $source->status);
        $this->assertSame('completed', $source->processing_stage);
        $this->assertNotNull($source->approved_at);
        $this->assertNotNull($source->indexed_at);
        $this->assertTrue($item->fresh()->approved);
        $this->assertTrue($item->fresh()->indexed);
        $this->assertSame('The QA beacon is ORQUIDEA-41.', $profile->fresh()->data['me']['description']);
        $this->assertDatabaseHas('profile_knowledge_chunks', [
            'profile_id' => $profile->id,
            'source_type' => 'profile_source_item',
            'source_id' => (string) $item->id,
            'active' => true,
        ]);
    }

    #[Test]
    public function deleting_source_removes_file_facts_derived_data_and_embedding_chunks(): void
    {
        Storage::fake('profiles');
        config()->set('profile-knowledge-ai.sources.disk', 'profiles');
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create([
            'data' => [
                'manual' => 'keep',
                'work' => [[
                    'description' => 'Delete this derived item',
                    'source' => 'cv',
                    'source_id' => 1,
                ]],
            ],
        ]);
        $path = "sources/{$profile->id}/source.txt";
        Storage::disk('profiles')->put($path, 'Delete this source');
        $source = ProfileSource::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'type' => 'cv',
            'name' => 'Disposable source',
            'storage_path' => $path,
            'status' => ProfileSourceStatus::Indexed,
            'approved_at' => now(),
            'indexed_at' => now(),
            'metadata' => ['file' => ['disk' => 'profiles']],
        ]);
        $profile->forceFill([
            'data' => [
                'manual' => 'keep',
                'work' => [[
                    'description' => 'Delete this derived item',
                    'source' => 'cv',
                    'source_id' => $source->id,
                ]],
            ],
        ])->saveQuietly();
        $item = ProfileSourceItem::query()->create([
            'profile_source_id' => $source->id,
            'profile_id' => $profile->id,
            'type' => 'experience',
            'content' => 'Delete this derived item',
            'approved' => true,
            'indexed' => true,
        ]);
        $fact = ProfileFact::query()->create([
            'profile_id' => $profile->id,
            'profile_source_id' => $source->id,
            'profile_source_item_id' => $item->id,
            'category' => 'experience',
            'text' => 'Delete this derived item',
            'visibility' => ProfileFactVisibility::Public,
            'approved' => true,
            'indexed' => true,
        ]);
        $this->chunk($profile, 'source-item', 'profile_source_item', (string) $item->id);
        $this->chunk($profile, 'source-fact', 'profile_fact', (string) $fact->id);
        $this->chunk($profile, 'derived-data', 'profile_data', 'work');
        $token = $user->createToken('test-token', ['profile:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->deleteJson("/api/profile/{$profile->id}/sources/{$source->id}");

        $response->assertOk()->assertJsonPath('message', 'Profile source deleted successfully.');
        $this->assertDatabaseMissing('profile_sources', ['id' => $source->id]);
        $this->assertDatabaseMissing('profile_facts', ['id' => $fact->id]);
        $this->assertDatabaseMissing('profile_source_items', ['id' => $item->id]);
        $this->assertDatabaseCount('profile_knowledge_chunks', 0);
        Storage::disk('profiles')->assertMissing($path);
        $this->assertSame('keep', $profile->fresh()->data['manual']);
        $this->assertArrayNotHasKey('work', $profile->fresh()->data);
        Queue::assertPushed(IndexProfileKnowledge::class, fn (IndexProfileKnowledge $job): bool => $job->profileId === $profile->id);
    }

    private function chunk(Profile $profile, string $key, string $sourceType, string $sourceId): void
    {
        ProfileKnowledgeChunk::query()->create([
            'profile_id' => $profile->id,
            'chunk_key' => $key,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'content' => 'Delete this derived item',
            'content_hash' => hash('sha256', $key),
            'embedding_model' => 'test',
            'embedding_dimensions' => 3,
            'active' => true,
        ]);
    }
}
