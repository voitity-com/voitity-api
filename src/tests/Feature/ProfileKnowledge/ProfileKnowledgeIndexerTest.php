<?php

declare(strict_types=1);

namespace Tests\Feature\ProfileKnowledge;

use App\Classes\EmbeddingService\EmbeddingClient;
use App\Enums\ProfileSourceStatus;
use App\Jobs\ProfileKnowledge\IndexProfileKnowledge;
use App\Models\Profile;
use App\Models\ProfileKnowledgeChunk;
use App\Models\ProfileSource;
use App\Models\ProfileSourceItem;
use App\Models\User;
use App\Services\ProfileKnowledge\ProfileKnowledgeIndexer;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeEmbeddingClient;
use Tests\TestCase;

class ProfileKnowledgeIndexerTest extends TestCase
{
    #[Test]
    public function it_always_schedules_profile_knowledge_indexing(): void
    {
        $profile = Profile::factory()->for(User::factory())->create();

        $this->assertDatabaseHas('profile_knowledge_indexes', [
            'profile_id' => $profile->id,
            'status' => 'pending',
        ]);
        Queue::assertPushed(
            IndexProfileKnowledge::class,
            fn (IndexProfileKnowledge $job): bool => $job->profileId === $profile->id
        );
    }

    #[Test]
    public function it_indexes_changed_documents_and_reuses_unchanged_embeddings(): void
    {
        config()->set('ai-knowledge.embedding.dimensions', 3);
        config()->set('ai-knowledge.embedding.drivers.openai.dimensions', 3);
        $profile = Profile::factory()->for(User::factory())->create([
            'data' => ['bio' => 'Builds Laravel applications.'],
            'networks' => ['github' => 'https://github.com/example'],
        ]);
        $fake = new FakeEmbeddingClient(fn (): array => [1.0, 0.0, 0.0]);
        $this->app->instance(EmbeddingClient::class, $fake);

        $first = app(ProfileKnowledgeIndexer::class)->index($profile);
        $second = app(ProfileKnowledgeIndexer::class)->index($profile->fresh());

        $this->assertSame('ready', $first['status']);
        $this->assertGreaterThan(0, $first['embedded_chunks']);
        $this->assertSame(0, $second['embedded_chunks']);
        $this->assertSame($first['total_chunks'], ProfileKnowledgeChunk::query()->where('profile_id', $profile->id)->count());
        $this->assertDatabaseHas('profile_knowledge_indexes', [
            'profile_id' => $profile->id,
            'status' => 'ready',
            'embedding_dimensions' => 3,
        ]);
        $this->assertNotNull(ProfileKnowledgeChunk::query()->where('profile_id', $profile->id)->value('embedding'));
    }

    #[Test]
    public function it_excludes_duplicate_and_empty_sources_and_reports_truthful_index_statuses(): void
    {
        config()->set('ai-knowledge.embedding.dimensions', 3);
        config()->set('ai-knowledge.embedding.drivers.openai.dimensions', 3);
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create();
        $canonical = ProfileSource::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'type' => 'cv',
            'name' => 'CV canonical',
            'status' => ProfileSourceStatus::Approved,
            'extracted_text' => 'Laravel engineer with PostgreSQL experience.',
            'approved_at' => now(),
        ]);
        $duplicate = ProfileSource::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'type' => 'cv',
            'name' => 'CV duplicate',
            'status' => ProfileSourceStatus::Approved,
            'extracted_text' => ' Laravel engineer with   PostgreSQL experience. ',
            'approved_at' => now(),
        ]);
        $empty = ProfileSource::query()->create([
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'type' => 'cv',
            'name' => 'Empty source',
            'status' => ProfileSourceStatus::Approved,
            'approved_at' => now(),
        ]);
        ProfileSourceItem::query()->create([
            'profile_source_id' => $canonical->id,
            'profile_id' => $profile->id,
            'type' => 'skills',
            'title' => 'Skills',
            'content' => 'Laravel and PostgreSQL.',
            'approved' => true,
        ]);
        $duplicateItem = ProfileSourceItem::query()->create([
            'profile_source_id' => $duplicate->id,
            'profile_id' => $profile->id,
            'type' => 'skills',
            'title' => 'Skills',
            'content' => 'Laravel and PostgreSQL.',
            'approved' => true,
        ]);
        $this->app->instance(EmbeddingClient::class, new FakeEmbeddingClient(fn (): array => [1.0, 0.0, 0.0]));

        app(ProfileKnowledgeIndexer::class)->index($profile->fresh());

        $this->assertSame($canonical->id, $duplicate->fresh()->duplicate_of_source_id);
        $this->assertSame(ProfileSourceStatus::Indexed, $canonical->fresh()->status);
        $this->assertSame(ProfileSourceStatus::Approved, $duplicate->fresh()->status);
        $this->assertNull($duplicate->fresh()->indexed_at);
        $this->assertSame(ProfileSourceStatus::Approved, $empty->fresh()->status);
        $this->assertNull($empty->fresh()->indexed_at);
        $this->assertDatabaseMissing('profile_knowledge_chunks', [
            'profile_id' => $profile->id,
            'chunk_key' => "source.item.{$duplicateItem->id}.0",
        ]);
    }
}
