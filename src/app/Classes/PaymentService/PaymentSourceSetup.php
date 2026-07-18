<?php

namespace App\Classes\PaymentService;

class PaymentSourceSetup
{
    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public readonly string $source,
        public readonly string $publicKey,
        public readonly string $apiUrl,
        public readonly ?string $environment,
        public readonly string $acceptanceToken,
        public readonly ?string $acceptancePermalink,
        public readonly string $personalDataAuthToken,
        public readonly ?string $personalDataAuthPermalink,
        public readonly array $rawResponse = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'public_key' => $this->publicKey,
            'api_url' => $this->apiUrl,
            'environment' => $this->environment,
            'acceptance' => [
                'acceptance_token' => $this->acceptanceToken,
                'permalink' => $this->acceptancePermalink,
            ],
            'personal_data_auth' => [
                'acceptance_token' => $this->personalDataAuthToken,
                'permalink' => $this->personalDataAuthPermalink,
            ],
        ];
    }
}
