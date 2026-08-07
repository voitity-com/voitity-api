<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\ChatAIService\OpenAI;

use App\Classes\ChatAIService\OpenAI\OpenAIClient;
use App\Classes\EmbeddingService\EmbeddingClient;
use App\Models\Profile;
use App\Models\ProfileKnowledgeChunk;
use App\Models\ProfileKnowledgeIndex;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeEmbeddingClient;
use Tests\TestCase;

class OpenAIKnowledgePromptTest extends TestCase
{
    #[Test]
    public function it_always_sends_only_retrieved_profile_knowledge(): void
    {
        config()->set('ai-knowledge.embedding.dimensions', 3);
        config()->set('ai-knowledge.retrieval.minimum_score', 0.1);
        $profile = Profile::factory()->for(User::factory())->create([
            'name' => 'Lex',
            'description' => 'Software architect',
            'data' => [
                'work' => [['company' => 'Legacy Secret Company']],
                'private_note' => 'LEGACY-CONTEXT-MUST-NOT-BE-SENT',
            ],
        ]);
        ProfileKnowledgeIndex::query()->updateOrCreate(
            ['profile_id' => $profile->id],
            [
                'status' => 'ready',
                'index_version' => config('ai-knowledge.indexing.version'),
                'embedding_model' => config('ai-knowledge.embedding.model'),
                'embedding_dimensions' => 3,
            ]
        );
        $content = 'Current public role: Principal architect at Acme.';
        $chunk = ProfileKnowledgeChunk::query()->create([
            'profile_id' => $profile->id,
            'chunk_key' => 'profile.data.work.0.0',
            'source_type' => 'profile_data',
            'source_id' => 'work',
            'content' => $content,
            'content_hash' => hash('sha256', $content),
            'metadata' => ['section' => 'work'],
            'visibility' => 'public',
            'active' => true,
            'embedding_model' => config('ai-knowledge.embedding.model'),
            'embedding_dimensions' => 3,
            'embedded_at' => now(),
        ]);
        $chunk->setAttribute('embedding', json_encode([1.0, 0.0, 0.0]));
        $chunk->save();
        $this->app->instance(EmbeddingClient::class, new FakeEmbeddingClient(fn (): array => [1.0, 0.0, 0.0]));
        Http::fake([
            'https://fake-openai.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"answer":"At Acme.","media_request":false,"media_action":"none","media_ids":[],"product_request":false,"product_action":"none","product_ids":[],"constraints":{"include_providers":[],"exclude_providers":[],"include_source_types":[],"exclude_source_types":[],"require_unseen":false}}'],
                    'finish_reason' => 'stop',
                ]],
            ]),
        ]);

        $answer = $this->client()->getAnswer($profile, 'Where do you work?');

        $this->assertSame('rag', $answer->response['_bigmelo']['knowledge']['mode']);
        $this->assertSame([$chunk->id], $answer->response['_bigmelo']['knowledge']['retrieved_chunk_ids']);
        Http::assertSent(function ($request): bool {
            $prompt = $request->data()['messages'][0]['content'];

            return str_contains($prompt, 'Retrieved profile knowledge relevant to this question')
                && str_contains($prompt, 'Principal architect at Acme')
                && ! str_contains($prompt, 'LEGACY-CONTEXT-MUST-NOT-BE-SENT')
                && ! str_contains($prompt, 'Legacy Secret Company');
        });
    }

    #[Test]
    public function it_builds_a_missing_index_instead_of_using_the_legacy_prompt(): void
    {
        config()->set('ai-knowledge.embedding.dimensions', 3);
        config()->set('ai-knowledge.retrieval.minimum_score', 0.1);
        $profile = Profile::factory()->for(User::factory())->create([
            'data' => ['bio' => 'On-demand indexed biography.'],
        ]);
        $this->app->instance(EmbeddingClient::class, new FakeEmbeddingClient(fn (): array => [1.0, 0.0, 0.0]));
        Http::fake([
            'https://fake-openai.test/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => '{}'], 'finish_reason' => 'stop']],
            ]),
        ]);

        $answer = $this->client()->getAnswer($profile, 'Tell me about yourself');

        $this->assertSame('rag', $answer->response['_bigmelo']['knowledge']['mode']);
        $this->assertArrayNotHasKey('fallback_reason', $answer->response['_bigmelo']['knowledge']);
        $this->assertNotEmpty($answer->response['_bigmelo']['knowledge']['retrieved_chunk_ids']);
        Http::assertSent(fn ($request): bool => str_contains($request->data()['messages'][0]['content'], 'On-demand indexed biography.')
            && ! str_contains($request->data()['messages'][0]['content'], 'Profile data:'));
        Http::assertSentCount(1);
    }

    private function client(): OpenAIClient
    {
        return new OpenAIClient(
            apiKey: 'test-key',
            baseUrl: 'https://fake-openai.test/v1',
            defaultModel: 'gpt-4o-mini',
        );
    }
}
