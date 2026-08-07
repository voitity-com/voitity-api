<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\EmbeddingService;

use App\Classes\EmbeddingService\OpenAI\OpenAIEmbeddingClient;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class OpenAIEmbeddingClientTest extends TestCase
{
    #[Test]
    public function it_embeds_batches_with_the_configured_model_and_dimensions(): void
    {
        Http::fake([
            'https://embeddings.test/v1/embeddings' => Http::response([
                'data' => [
                    ['index' => 1, 'embedding' => [0.0, 1.0, 0.0]],
                    ['index' => 0, 'embedding' => [1.0, 0.0, 0.0]],
                ],
                'model' => 'text-embedding-3-small',
                'usage' => ['total_tokens' => 9],
            ]),
        ]);

        $client = new OpenAIEmbeddingClient(
            apiKey: 'embedding-key',
            baseUrl: 'https://embeddings.test/v1',
            model: 'text-embedding-3-small',
            dimensions: 3,
        );
        $result = $client->embed(['first text', 'second text']);

        $this->assertSame([[1.0, 0.0, 0.0], [0.0, 1.0, 0.0]], $result->vectors);
        $this->assertSame(9, $result->inputTokens);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://embeddings.test/v1/embeddings'
            && $request['model'] === 'text-embedding-3-small'
            && $request['dimensions'] === 3
            && $request['input'] === ['first text', 'second text']);
    }

    #[Test]
    public function it_rejects_invalid_embedding_dimensions(): void
    {
        Http::fake([
            'https://embeddings.test/v1/embeddings' => Http::response([
                'data' => [['index' => 0, 'embedding' => [1.0, 0.0]]],
            ]),
        ]);

        $client = new OpenAIEmbeddingClient(
            apiKey: 'embedding-key',
            baseUrl: 'https://embeddings.test/v1',
            dimensions: 3,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unexpected vector count or dimensions');
        $client->embed(['text']);
    }
}
