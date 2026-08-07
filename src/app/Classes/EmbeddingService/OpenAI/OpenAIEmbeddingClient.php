<?php

namespace App\Classes\EmbeddingService\OpenAI;

use App\Classes\EmbeddingService\EmbeddingClient;
use App\Classes\EmbeddingService\EmbeddingResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenAIEmbeddingClient implements EmbeddingClient
{
    private string $apiKey;

    private string $baseUrl;

    private string $model;

    private int $dimensions;

    private int $retryAttempts;

    private int $retryDelayMs;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $model = null,
        ?int $dimensions = null,
        ?int $retryAttempts = null,
        ?int $retryDelayMs = null,
    ) {
        $this->apiKey = (string) ($apiKey ?: config('services.openai.api_key'));
        $this->baseUrl = rtrim((string) ($baseUrl ?: 'https://api.openai.com/v1'), '/');
        $this->model = (string) ($model ?: 'text-embedding-3-small');
        $this->dimensions = max(1, $dimensions ?: 1536);
        $this->retryAttempts = max(1, $retryAttempts ?: 1);
        $this->retryDelayMs = max(0, $retryDelayMs ?? 0);
    }

    public function embed(array $inputs): EmbeddingResult
    {
        $inputs = array_values(array_map(fn (string $input): string => trim($input), $inputs));

        if ($inputs === [] || in_array('', $inputs, true)) {
            throw new RuntimeException('Embedding inputs must contain non-empty text.');
        }

        if ($this->apiKey === '') {
            throw new RuntimeException('OpenAI API key is not configured for embeddings.');
        }

        $requestUrl = $this->baseUrl.'/embeddings';
        $response = $this->request()
            ->post($requestUrl, [
                'model' => $this->model,
                'input' => $inputs,
                'encoding_format' => 'float',
                'dimensions' => $this->dimensions,
            ]);
        $responseData = $response->json() ?: [];

        if (! $response->successful()) {
            Log::error('OpenAI embedding API error.', [
                'status' => $response->status(),
                'request_url' => $requestUrl,
                'model' => $this->model,
                'input_count' => count($inputs),
                'response' => $responseData,
            ]);

            throw new RuntimeException("OpenAI embedding request failed with HTTP {$response->status()}.");
        }

        $vectors = collect((array) ($responseData['data'] ?? []))
            ->sortBy('index')
            ->map(fn (array $item): array => array_map('floatval', (array) ($item['embedding'] ?? [])))
            ->values()
            ->all();

        if (count($vectors) !== count($inputs) || collect($vectors)->contains(fn (array $vector): bool => count($vector) !== $this->dimensions)) {
            throw new RuntimeException('OpenAI embedding response returned an unexpected vector count or dimensions.');
        }

        return new EmbeddingResult(
            source: 'openai',
            model: (string) ($responseData['model'] ?? $this->model),
            vectors: $vectors,
            inputTokens: (int) data_get($responseData, 'usage.total_tokens', data_get($responseData, 'usage.prompt_tokens', 0)),
            response: $responseData,
        );
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(45)
            ->retry(
                $this->retryAttempts,
                $this->retryDelayMs,
                throw: false,
            );
    }
}
