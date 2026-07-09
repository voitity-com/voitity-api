<?php

namespace App\Classes\PaymentService;

class PaymentSourceCharge
{
    /**
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public readonly string $source,
        public readonly string $reference,
        public readonly int $amountInCents,
        public readonly string $currency,
        public readonly ?string $providerTransactionId,
        public readonly ?string $providerStatus,
        public readonly string $status,
        public readonly ?int $httpStatus = null,
        public readonly ?string $requestUrl = null,
        public readonly array $requestPayload = [],
        public readonly array $rawResponse = [],
    ) {}

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'approved';
    }

    public function isFailed(): bool
    {
        return in_array($this->status, ['declined', 'voided', 'error', 'expired'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'reference' => $this->reference,
            'amount_in_cents' => $this->amountInCents,
            'currency' => $this->currency,
            'provider_transaction_id' => $this->providerTransactionId,
            'provider_status' => $this->providerStatus,
            'status' => $this->status,
            'http_status' => $this->httpStatus,
            'request_url' => $this->requestUrl,
            'request_payload' => $this->requestPayload,
            'raw_response' => $this->rawResponse,
        ];
    }
}
