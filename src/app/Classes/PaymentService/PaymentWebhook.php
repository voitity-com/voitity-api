<?php

namespace App\Classes\PaymentService;

class PaymentWebhook
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $transaction
     */
    public function __construct(
        public readonly string $source,
        public readonly string $event,
        public readonly string $providerEventId,
        public readonly bool $isValidSignature,
        public readonly ?string $checksum,
        public readonly ?string $calculatedChecksum,
        public readonly ?string $reference,
        public readonly ?string $providerTransactionId,
        public readonly ?int $amountInCents,
        public readonly ?string $currency,
        public readonly ?string $providerStatus,
        public readonly string $status,
        public readonly array $payload,
        public readonly array $transaction = [],
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
            'event' => $this->event,
            'provider_event_id' => $this->providerEventId,
            'is_valid_signature' => $this->isValidSignature,
            'checksum' => $this->checksum,
            'calculated_checksum' => $this->calculatedChecksum,
            'reference' => $this->reference,
            'provider_transaction_id' => $this->providerTransactionId,
            'amount_in_cents' => $this->amountInCents,
            'currency' => $this->currency,
            'provider_status' => $this->providerStatus,
            'status' => $this->status,
            'transaction' => $this->transaction,
            'payload' => $this->payload,
        ];
    }
}
