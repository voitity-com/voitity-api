<?php

namespace App\Classes\PaymentService;

class PaymentSourceCreateResult
{
    /**
     * @param  array<string, mixed>  $publicData
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public readonly string $source,
        public readonly ?string $providerSourceId,
        public readonly string $type,
        public readonly ?string $providerStatus,
        public readonly string $status,
        public readonly bool $reusable,
        public readonly ?int $httpStatus = null,
        public readonly ?string $requestUrl = null,
        public readonly array $requestPayload = [],
        public readonly array $publicData = [],
        public readonly array $rawResponse = [],
    ) {}

    public function isActive(): bool
    {
        return $this->status === 'active'
            && $this->providerSourceId !== null
            && trim($this->providerSourceId) !== ''
            && $this->reusable;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'provider_source_id' => $this->providerSourceId,
            'type' => $this->type,
            'provider_status' => $this->providerStatus,
            'status' => $this->status,
            'reusable' => $this->reusable,
            'http_status' => $this->httpStatus,
            'request_url' => $this->requestUrl,
            'request_payload' => $this->requestPayload,
            'public_data' => $this->publicData,
            'raw_response' => $this->rawResponse,
        ];
    }
}
