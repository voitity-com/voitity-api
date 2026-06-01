<?php

namespace App\Classes\VideoAIService\Runway;

use App\Classes\VideoAIService\VideoAIClient;
use App\Classes\VideoAIService\AiImage;
use App\Classes\VideoAIService\AiVideo;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class RunwayVideoAI implements VideoAIClient
{
    protected string $apiKey;
    protected string $baseUrl;
    protected string $apiVersion;
    protected string $imageModel;
    protected string $videoModel;
    protected string $referenceImageTag;
    protected string $defaultImageRatio;
    protected string $defaultVideoRatio;
    protected int $defaultDuration;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?string $apiVersion = null,
        ?string $imageModel = null,
        ?string $videoModel = null,
        ?string $referenceImageTag = null,
        ?string $defaultImageRatio = null,
        ?string $defaultVideoRatio = null,
        ?int $defaultDuration = null
    ) {
        $this->apiKey = $apiKey ?: (string) config('videoai.drivers.runway.api_key');
        $this->baseUrl = rtrim($baseUrl ?: (string) config('videoai.drivers.runway.base_url', 'https://api.dev.runwayml.com'), '/');
        $this->apiVersion = $apiVersion ?: (string) config('videoai.drivers.runway.api_version', '2024-11-06');
        $this->imageModel = $imageModel ?: (string) config('videoai.drivers.runway.image_model', 'gen4_image');
        $this->videoModel = $videoModel ?: (string) config('videoai.drivers.runway.video_model', 'gen4.5');
        $this->referenceImageTag = $referenceImageTag ?: (string) config('videoai.drivers.runway.reference_image_tag', 'base_image');
        $this->defaultImageRatio = $defaultImageRatio ?: (string) config('videoai.drivers.runway.default_image_ratio', '1024:1024');
        $this->defaultVideoRatio = $defaultVideoRatio ?: (string) config('videoai.drivers.runway.default_video_ratio', '960:960');
        $this->defaultDuration = $defaultDuration ?: (int) config('videoai.drivers.runway.default_duration', 5);

        if (!$this->apiKey) {
            throw new InvalidArgumentException('Runway API key is not configured');
        }
    }

    public function createImage(string $sourceImage, string $prompt, string $ratio = ''): AiImage
    {
        $requestUrl = "{$this->baseUrl}/v1/text_to_image";
        $ratio = $ratio ?: $this->defaultImageRatio;

        try {
            Log::info('Runway: Starting image generation', [
                'model' => $this->imageModel,
                'ratio' => $ratio,
            ]);

            $response = Http::withHeaders($this->headers())->post($requestUrl, [
                'model' => $this->imageModel,
                'promptText' => $prompt,
                'referenceImages' => [
                    [
                        'uri' => $sourceImage,
                        'tag' => $this->referenceImageTag,
                    ],
                ],
                'ratio' => $ratio,
            ]);

            $responseData = $this->responseData($response);

            if ($response->successful()) {
                return $this->toImage($responseData, $requestUrl, 'PENDING');
            }

            Log::error('Runway: Image generation failed', [
                'status' => $response->status(),
                'response' => $responseData,
                'request_url' => $requestUrl,
            ]);

            return new AiImage(
                status: 'failed',
                response: $responseData,
                requestUrl: $requestUrl
            );
        } catch (Throwable $e) {
            Log::error('Runway: Image generation exception', [
                'error' => $e->getMessage(),
                'request_url' => $requestUrl,
            ]);

            return new AiImage(
                status: 'error',
                response: ['error' => $e->getMessage()],
                requestUrl: $requestUrl
            );
        }
    }

    public function createVideo(string $sourceImage, string $prompt, string $ratio = '', int $duration = 5): AiVideo
    {
        $requestUrl = "{$this->baseUrl}/v1/image_to_video";
        $ratio = $ratio ?: $this->defaultVideoRatio;
        $duration = $duration ?: $this->defaultDuration;

        try {
            Log::info('Runway: Starting video generation', [
                'model' => $this->videoModel,
                'ratio' => $ratio,
                'duration' => $duration,
            ]);

            $response = Http::withHeaders($this->headers())->post($requestUrl, [
                'model' => $this->videoModel,
                'promptImage' => $sourceImage,
                'promptText' => $prompt,
                'ratio' => $ratio,
                'duration' => $duration,
            ]);

            $responseData = $this->responseData($response);

            if ($response->successful()) {
                return $this->toVideo($responseData, $requestUrl, 'PENDING');
            }

            Log::error('Runway: Video generation failed', [
                'status' => $response->status(),
                'response' => $responseData,
                'request_url' => $requestUrl,
            ]);

            return new AiVideo(
                status: 'failed',
                response: $responseData,
                requestUrl: $requestUrl
            );
        } catch (Throwable $e) {
            Log::error('Runway: Video generation exception', [
                'error' => $e->getMessage(),
                'request_url' => $requestUrl,
            ]);

            return new AiVideo(
                status: 'error',
                response: ['error' => $e->getMessage()],
                requestUrl: $requestUrl
            );
        }
    }

    public function getImage(string $sourceId): AiImage
    {
        $requestUrl = "{$this->baseUrl}/v1/tasks/{$sourceId}";

        try {
            $response = Http::withHeaders($this->headers())->get($requestUrl);
            $responseData = $this->responseData($response);

            if ($response->successful()) {
                return $this->toImage($responseData, $requestUrl);
            }

            Log::error('Runway: Get image task failed', [
                'source_id' => $sourceId,
                'status' => $response->status(),
                'response' => $responseData,
                'request_url' => $requestUrl,
            ]);

            return new AiImage(
                id: $sourceId,
                status: 'failed',
                response: $responseData,
                requestUrl: $requestUrl
            );
        } catch (Throwable $e) {
            Log::error('Runway: Get image task exception', [
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
                'request_url' => $requestUrl,
            ]);

            return new AiImage(
                id: $sourceId,
                status: 'error',
                response: ['error' => $e->getMessage()],
                requestUrl: $requestUrl
            );
        }
    }

    public function getVideo(string $sourceId): AiVideo
    {
        $requestUrl = "{$this->baseUrl}/v1/tasks/{$sourceId}";

        try {
            $response = Http::withHeaders($this->headers())->get($requestUrl);
            $responseData = $this->responseData($response);

            if ($response->successful()) {
                return $this->toVideo($responseData, $requestUrl);
            }

            Log::error('Runway: Get video task failed', [
                'source_id' => $sourceId,
                'status' => $response->status(),
                'response' => $responseData,
                'request_url' => $requestUrl,
            ]);

            return new AiVideo(
                id: $sourceId,
                status: 'failed',
                response: $responseData,
                requestUrl: $requestUrl
            );
        } catch (Throwable $e) {
            Log::error('Runway: Get video task exception', [
                'source_id' => $sourceId,
                'error' => $e->getMessage(),
                'request_url' => $requestUrl,
            ]);

            return new AiVideo(
                id: $sourceId,
                status: 'error',
                response: ['error' => $e->getMessage()],
                requestUrl: $requestUrl
            );
        }
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
            'X-Runway-Version' => $this->apiVersion,
        ];
    }

    protected function toImage(array $data, ?string $requestUrl = null, string $defaultStatus = 'pending'): AiImage
    {
        return new AiImage(
            id: $this->extractId($data),
            createdAt: $this->extractCreatedAt($data),
            status: $this->extractStatus($data, $defaultStatus),
            output: $this->normalizeOutput($data['output'] ?? []),
            response: $data,
            requestUrl: $requestUrl
        );
    }

    protected function toVideo(array $data, ?string $requestUrl = null, string $defaultStatus = 'pending'): AiVideo
    {
        return new AiVideo(
            id: $this->extractId($data),
            createdAt: $this->extractCreatedAt($data),
            status: $this->extractStatus($data, $defaultStatus),
            output: $this->normalizeOutput($data['output'] ?? []),
            response: $data,
            requestUrl: $requestUrl
        );
    }

    protected function extractId(array $data): ?string
    {
        return $data['id'] ?? $data['taskId'] ?? $data['uuid'] ?? null;
    }

    protected function extractCreatedAt(array $data): ?string
    {
        return $data['createdAt'] ?? $data['created_at'] ?? null;
    }

    protected function extractStatus(array $data, string $defaultStatus): string
    {
        return $data['status'] ?? $defaultStatus;
    }

    protected function normalizeOutput(mixed $output): array
    {
        if (is_array($output)) {
            return array_values($output);
        }

        if (is_string($output) && $output !== '') {
            return [$output];
        }

        return [];
    }

    protected function responseData($response): array
    {
        $json = $response->json();

        if (is_array($json)) {
            return $json;
        }

        return ['body' => $response->body()];
    }
}
