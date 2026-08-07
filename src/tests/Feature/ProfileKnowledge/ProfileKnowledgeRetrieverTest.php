<?php

declare(strict_types=1);

namespace Tests\Feature\ProfileKnowledge;

use App\Classes\EmbeddingService\EmbeddingClient;
use App\Models\Profile;
use App\Models\ProfileKnowledgeChunk;
use App\Models\ProfileKnowledgeIndex;
use App\Models\User;
use App\Services\ProfileKnowledge\ProfileKnowledgePromptContextService;
use App\Services\ProfileKnowledge\ProfileKnowledgeRetriever;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeEmbeddingClient;
use Tests\TestCase;

class ProfileKnowledgeRetrieverTest extends TestCase
{
    #[Test]
    public function it_ranks_semantic_context_and_forces_integration_inventory_for_media_requests(): void
    {
        [$profile] = $this->readyProfile();
        $this->chunk($profile, 'profile.data.work.0', 'profile_data', 'work', 'Backend engineer at Acme.', [1.0, 0.0, 0.0]);
        $media = $this->chunk($profile, 'integration.media.44', 'integration_media', '44', 'Photo about cloud architecture.', [0.0, 1.0, 0.0]);
        $this->chunk($profile, 'profile.data.hobby.0', 'profile_data', 'hobby', 'Enjoys baking bread.', [0.0, 0.0, 1.0]);

        $result = app(ProfileKnowledgeRetriever::class)->retrieve($profile, 'Muéstrame una foto de arquitectura');

        $this->assertContains($media->id, $result->chunkIds());
        $this->assertContains('44', $result->sourceIds('integration_media'));
        $this->assertLessThanOrEqual((int) config('ai-knowledge.retrieval.max_context_tokens'), $result->contextTokens);
    }

    #[Test]
    public function it_builds_a_missing_index_on_demand_and_uses_rag(): void
    {
        config()->set('ai-knowledge.embedding.dimensions', 3);
        $this->app->instance(EmbeddingClient::class, new FakeEmbeddingClient(fn (): array => [1.0, 0.0, 0.0]));
        $profile = Profile::factory()->for(User::factory())->create(['data' => ['bio' => 'Current profile data']]);
        $context = app(ProfileKnowledgePromptContextService::class)->build($profile, 'Who are you?');

        $this->assertNotEmpty($context->retrieval->items);
        $this->assertSame('ready', $profile->knowledgeIndex()->firstOrFail()->status->value);
        $this->assertSame('rag', $context->metadata()['mode']);
        $this->assertArrayNotHasKey('fallback_reason', $context->metadata());
    }

    #[Test]
    public function it_prioritizes_an_exact_product_reference_over_a_closer_semantic_result(): void
    {
        [$profile] = $this->readyProfile();
        $this->chunk($profile, 'product.1', 'product', '1', 'Adidas Tiro Club football.', [1.0, 0.0, 0.0]);
        $correct = $this->chunk(
            $profile,
            'product.2',
            'product',
            '2',
            'Puma Orbita 6 MS. Product reference 61385. Size 5.',
            [0.0, 1.0, 0.0]
        );

        $result = app(ProfileKnowledgeRetriever::class)->retrieve($profile, '¿Cuál es el balón con referencia 61385?');

        $this->assertSame($correct->id, $result->items[0]['chunk_id']);
        $this->assertSame(1.0, $result->items[0]['identifier_score']);
        $this->assertGreaterThan(0, $result->items[0]['lexical_score']);
    }

    #[Test]
    public function it_excludes_integration_media_from_a_direct_social_profile_request(): void
    {
        [$profile] = $this->readyProfile();
        $media = $this->chunk(
            $profile,
            'integration.media.5',
            'integration_media',
            '5',
            'GitHub project repository.',
            [1.0, 0.0, 0.0]
        );
        $social = $this->chunk(
            $profile,
            'social.github',
            'social_link',
            'github',
            'Social network github: https://github.com/aosmorac',
            [0.0, 1.0, 0.0]
        );

        $result = app(ProfileKnowledgeRetriever::class)->retrieve($profile, 'Llévame a tu perfil de GitHub.');

        $this->assertContains($social->id, $result->chunkIds());
        $this->assertNotContains($media->id, $result->chunkIds());
    }

    /** @return array{Profile} */
    private function readyProfile(): array
    {
        config()->set('ai-knowledge.embedding.dimensions', 3);
        config()->set('ai-knowledge.retrieval.minimum_score', 0.1);
        config()->set('ai-knowledge.retrieval.max_context_tokens', 500);
        $profile = Profile::factory()->for(User::factory())->create();
        ProfileKnowledgeIndex::query()->updateOrCreate(
            ['profile_id' => $profile->id],
            [
                'status' => 'ready',
                'index_version' => config('ai-knowledge.indexing.version'),
                'embedding_model' => config('ai-knowledge.embedding.model'),
                'embedding_dimensions' => 3,
            ]
        );
        $this->app->instance(EmbeddingClient::class, new FakeEmbeddingClient(fn (): array => [1.0, 0.0, 0.0]));

        return [$profile];
    }

    /** @param array<int, float> $embedding */
    private function chunk(Profile $profile, string $key, string $type, string $sourceId, string $content, array $embedding): ProfileKnowledgeChunk
    {
        $chunk = ProfileKnowledgeChunk::query()->create([
            'profile_id' => $profile->id,
            'chunk_key' => $key,
            'source_type' => $type,
            'source_id' => $sourceId,
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'metadata' => [],
            'visibility' => 'public',
            'active' => true,
            'embedding_model' => config('ai-knowledge.embedding.model'),
            'embedding_dimensions' => 3,
            'embedded_at' => now(),
        ]);
        $chunk->setAttribute('embedding', json_encode($embedding));
        $chunk->save();

        return $chunk;
    }
}
