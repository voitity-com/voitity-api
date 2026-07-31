<?php

namespace Tests\Unit\Classes\PaymentService;

use App\Classes\PaymentService\PaymentPayloadSanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PaymentPayloadSanitizerTest extends TestCase
{
    #[Test]
    public function it_keeps_only_allowed_payment_diagnostics(): void
    {
        $payload = [
            'source' => 'wompi',
            'provider_transaction_id' => 'trx_1',
            'provider_source_id' => 'source-secret',
            'provider_status' => 'APPROVED',
            'status' => 'approved',
            'http_status' => 201,
            'request_url' => 'https://sandbox.wompi.co/v1/transactions?token=secret',
            'request_payload' => ['token' => 'secret', 'cvc' => '123'],
            'raw_response' => ['data' => ['card_number' => '4242424242424242']],
        ];

        $sanitized = (new PaymentPayloadSanitizer)->paymentResult($payload);

        $this->assertSame([
            'source' => 'wompi',
            'provider_transaction_id' => 'trx_1',
            'provider_status' => 'APPROVED',
            'status' => 'approved',
            'http_status' => 201,
            'request_url' => 'https://sandbox.wompi.co/v1/transactions',
        ], $sanitized);
    }

    #[Test]
    public function it_keeps_only_a_minimal_webhook_snapshot(): void
    {
        $sanitized = (new PaymentPayloadSanitizer)->webhook([
            'event' => 'transaction.updated',
            'sent_at' => '2026-07-30T12:00:00Z',
            'signature' => ['checksum' => 'secret'],
            'data' => [
                'transaction' => [
                    'id' => 'trx_1',
                    'reference' => 'order-1',
                    'status' => 'APPROVED',
                    'amount_in_cents' => 4000000,
                    'currency' => 'COP',
                    'payment_method_type' => 'CARD',
                    'payment_method' => [
                        'number' => '4242424242424242',
                        'cvc' => '123',
                    ],
                ],
            ],
        ]);

        $this->assertSame('transaction.updated', $sanitized['event']);
        $this->assertSame('trx_1', $sanitized['transaction']['id']);
        $this->assertArrayNotHasKey('signature', $sanitized);
        $this->assertArrayNotHasKey('payment_method', $sanitized['transaction']);
    }
}
