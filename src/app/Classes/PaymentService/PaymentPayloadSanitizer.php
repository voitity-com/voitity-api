<?php

namespace App\Classes\PaymentService;

class PaymentPayloadSanitizer
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function paymentResult(array $payload): array
    {
        return array_filter([
            'source' => $this->scalar($payload['source'] ?? null),
            'provider_transaction_id' => $this->scalar($payload['provider_transaction_id'] ?? null),
            'provider_status' => $this->scalar($payload['provider_status'] ?? null),
            'status' => $this->scalar($payload['status'] ?? null),
            'http_status' => is_numeric($payload['http_status'] ?? null)
                ? (int) $payload['http_status']
                : null,
            'request_url' => $this->safeRequestUrl($payload['request_url'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function webhook(array $payload): array
    {
        $transaction = data_get($payload, 'data.transaction');

        if (! is_array($transaction)) {
            $transaction = [];
        }

        return array_filter([
            'event' => $this->scalar($payload['event'] ?? null),
            'sent_at' => $this->scalar($payload['sent_at'] ?? null),
            'transaction' => array_filter([
                'id' => $this->scalar($transaction['id'] ?? null),
                'reference' => $this->scalar($transaction['reference'] ?? null),
                'status' => $this->scalar($transaction['status'] ?? null),
                'amount_in_cents' => is_numeric($transaction['amount_in_cents'] ?? null)
                    ? (int) $transaction['amount_in_cents']
                    : null,
                'currency' => $this->scalar($transaction['currency'] ?? null),
                'payment_method_type' => $this->scalar($transaction['payment_method_type'] ?? null),
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    private function scalar(mixed $value): string|int|float|bool|null
    {
        return is_scalar($value) ? $value : null;
    }

    private function safeRequestUrl(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        $parts = parse_url($value);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].($parts['path'] ?? '');
    }
}
