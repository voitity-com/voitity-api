<?php

namespace Tests\Unit\Classes\PaymentService;

use App\Classes\PaymentService\PaymentRequest;
use App\Classes\PaymentService\Wompi\WompiPaymentClient;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WompiPaymentClientTest extends TestCase
{
    #[Test]
    public function it_generates_integrity_signature_without_expiration_time(): void
    {
        $signature = WompiPaymentClient::createIntegritySignature(
            reference: 'sk8-438k4-xmxm392-sn2m',
            amountInCents: 2490000,
            currency: 'COP',
            integritySecret: 'prod_integrity_Z5mMke9x0k8gpErbDqwrJXMqsI6SFli6',
        );

        $this->assertSame(
            hash('sha256', 'sk8-438k4-xmxm392-sn2m2490000COPprod_integrity_Z5mMke9x0k8gpErbDqwrJXMqsI6SFli6'),
            $signature,
        );
    }

    #[Test]
    public function it_creates_wompi_checkout_intent(): void
    {
        $client = $this->client();

        $intent = $client->createPayment(new PaymentRequest(
            reference: 'VOI-1-TEST',
            amountInCents: 3200000,
            currency: 'COP',
            redirectUrl: 'http://localhost:5173/checkout/result',
            customerData: ['email' => 'user@example.com', 'full-name' => 'Test User'],
        ));

        $this->assertSame('wompi', $intent->source);
        $this->assertSame('VOI-1-TEST', $intent->reference);
        $this->assertSame(3200000, $intent->amountInCents);
        $this->assertSame('COP', $intent->currency);
        $this->assertSame('pub_test_key', $intent->publicKey);
        $this->assertSame('https://checkout.wompi.co/widget.js', $intent->widgetUrl);
        $this->assertStringStartsWith('https://checkout.wompi.co/p/?', $intent->checkoutUrl);
        $this->assertSame('VOI-1-TEST', $intent->formParameters['reference']);
        $this->assertSame('user@example.com', $intent->formParameters['customer-data:email']);
        $this->assertSame(['integrity' => $intent->integritySignature], $intent->widgetConfig['signature']);
        $this->assertTrue($intent->isPending());
    }

    #[Test]
    public function it_requires_public_key_and_integrity_secret_for_checkout(): void
    {
        $client = new WompiPaymentClient(
            publicKey: null,
            integritySecret: null,
            eventsSecret: 'test_events_key',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Wompi public key and integrity secret are required.');

        $client->createPayment(new PaymentRequest(
            reference: 'VOI-1-TEST',
            amountInCents: 3200000,
            currency: 'COP',
        ));
    }

    #[Test]
    public function it_parses_valid_webhook_checksum_from_header(): void
    {
        $client = $this->client();
        $payload = $this->webhookPayload();
        $jsonPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        $checksum = $this->eventChecksum($payload, 'test_events_key');

        $webhook = $client->parseWebhook(['X-Event-Checksum' => $checksum], $jsonPayload);

        $this->assertTrue($webhook->isValidSignature);
        $this->assertTrue($webhook->isSuccessful());
        $this->assertSame('transaction.updated', $webhook->event);
        $this->assertSame('evt_test_1', $webhook->providerEventId);
        $this->assertSame('VOI-1-TEST', $webhook->reference);
        $this->assertSame('trx_test_1', $webhook->providerTransactionId);
        $this->assertSame(3200000, $webhook->amountInCents);
        $this->assertSame('COP', $webhook->currency);
        $this->assertSame('APPROVED', $webhook->providerStatus);
        $this->assertSame('approved', $webhook->status);
    }

    #[Test]
    public function it_rejects_invalid_webhook_checksum(): void
    {
        $webhook = $this->client()->parseWebhook(
            ['X-Event-Checksum' => 'bad-checksum'],
            json_encode($this->webhookPayload(), JSON_THROW_ON_ERROR),
        );

        $this->assertFalse($webhook->isValidSignature);
        $this->assertSame('approved', $webhook->status);
    }

    #[Test]
    public function it_uses_body_checksum_when_header_is_missing(): void
    {
        $client = $this->client();
        $payload = $this->webhookPayload();
        $payload['signature']['checksum'] = $this->eventChecksum($payload, 'test_events_key');

        $webhook = $client->parseWebhook([], json_encode($payload, JSON_THROW_ON_ERROR));

        $this->assertTrue($webhook->isValidSignature);
    }

    private function client(): WompiPaymentClient
    {
        return new WompiPaymentClient(
            publicKey: 'pub_test_key',
            integritySecret: 'test_integrity_key',
            eventsSecret: 'test_events_key',
            checkoutUrl: 'https://checkout.wompi.co/p/',
            widgetUrl: 'https://checkout.wompi.co/widget.js',
            environment: 'sandbox',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function webhookPayload(): array
    {
        return [
            'id' => 'evt_test_1',
            'event' => 'transaction.updated',
            'data' => [
                'transaction' => [
                    'id' => 'trx_test_1',
                    'amount_in_cents' => 3200000,
                    'reference' => 'VOI-1-TEST',
                    'currency' => 'COP',
                    'status' => 'APPROVED',
                ],
            ],
            'environment' => 'test',
            'signature' => [
                'properties' => [
                    'transaction.id',
                    'transaction.status',
                    'transaction.amount_in_cents',
                ],
                'checksum' => 'not-used-when-header-is-present',
            ],
            'timestamp' => 1530291411,
            'sent_at' => '2018-07-20T16:45:05.000Z',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function eventChecksum(array $payload, string $eventsSecret): string
    {
        $transaction = $payload['data']['transaction'];

        return hash(
            'sha256',
            $transaction['id'].$transaction['status'].$transaction['amount_in_cents'].$payload['timestamp'].$eventsSecret,
        );
    }
}
