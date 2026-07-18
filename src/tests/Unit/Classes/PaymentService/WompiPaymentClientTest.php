<?php

namespace Tests\Unit\Classes\PaymentService;

use App\Classes\PaymentService\PaymentRequest;
use App\Classes\PaymentService\PaymentSourceCreateRequest;
use App\Classes\PaymentService\PaymentSourceChargeRequest;
use App\Classes\PaymentService\Wompi\WompiPaymentClient;
use Illuminate\Support\Facades\Http;
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
            redirectUrl: 'http://localhost:5173/dashboard/settings/billing/payment-result',
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
    public function it_gets_wompi_payment_source_setup_tokens(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/merchants/pub_test_key' => Http::response([
                'data' => [
                    'presigned_acceptance' => [
                        'acceptance_token' => 'acceptance-token',
                        'permalink' => 'https://wompi.test/terms.pdf',
                    ],
                    'presigned_personal_data_auth' => [
                        'acceptance_token' => 'personal-auth-token',
                        'permalink' => 'https://wompi.test/privacy.pdf',
                    ],
                ],
            ]),
        ]);

        $setup = $this->client()->paymentSourceSetup();

        $this->assertSame('wompi', $setup->source);
        $this->assertSame('pub_test_key', $setup->publicKey);
        $this->assertSame('https://sandbox.wompi.co/v1', $setup->apiUrl);
        $this->assertSame('acceptance-token', $setup->acceptanceToken);
        $this->assertSame('personal-auth-token', $setup->personalDataAuthToken);
        $this->assertSame('https://wompi.test/terms.pdf', $setup->acceptancePermalink);
    }

    #[Test]
    public function it_creates_wompi_payment_source(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/payment_sources' => Http::response([
                'data' => [
                    'id' => 3891,
                    'type' => 'CARD',
                    'status' => 'AVAILABLE',
                    'public_data' => [
                        'brand' => 'VISA',
                        'last_four' => '4242',
                    ],
                ],
            ], 201),
        ]);

        $paymentSource = $this->client()->createPaymentSource(new PaymentSourceCreateRequest(
            customerEmail: 'user@example.com',
            type: 'CARD',
            token: 'tok_test_4242',
            acceptanceToken: 'acceptance-token',
            acceptPersonalAuth: 'personal-auth-token',
            sessionId: 'session-1',
            customerData: ['device_id' => 'device-1'],
        ));

        $this->assertTrue($paymentSource->isActive());
        $this->assertSame('3891', $paymentSource->providerSourceId);
        $this->assertSame('CARD', $paymentSource->type);
        $this->assertSame('AVAILABLE', $paymentSource->providerStatus);
        $this->assertSame('active', $paymentSource->status);
        $this->assertSame('VISA', $paymentSource->publicData['brand']);
        $this->assertSame('[redacted]', $paymentSource->requestPayload['token']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://sandbox.wompi.co/v1/payment_sources'
                && ($request->header('Authorization')[0] ?? null) === 'Bearer prv_test_key'
                && $request['type'] === 'CARD'
                && $request['token'] === 'tok_test_4242'
                && $request['customer_email'] === 'user@example.com'
                && $request['acceptance_token'] === 'acceptance-token'
                && $request['accept_personal_auth'] === 'personal-auth-token'
                && $request['session_id'] === 'session-1'
                && $request['customer_data']['device_id'] === 'device-1';
        });
    }

    #[Test]
    public function it_charges_wompi_payment_source_as_recurring_transaction(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/transactions' => Http::response([
                'data' => [
                    'id' => 'trx_renewal_1',
                    'reference' => 'VOI-REN-1-TEST',
                    'status' => 'APPROVED',
                    'amount_in_cents' => 3200000,
                    'currency' => 'COP',
                ],
            ], 201),
        ]);

        $charge = $this->client()->chargePaymentSource(new PaymentSourceChargeRequest(
            reference: 'VOI-REN-1-TEST',
            amountInCents: 3200000,
            currency: 'COP',
            customerEmail: 'user@example.com',
            paymentSourceProviderId: '3891',
            installments: 1,
            recurrent: true,
        ));

        $this->assertTrue($charge->isSuccessful());
        $this->assertSame('approved', $charge->status);
        $this->assertSame('trx_renewal_1', $charge->providerTransactionId);
        $this->assertSame('APPROVED', $charge->providerStatus);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://sandbox.wompi.co/v1/transactions'
                && ($request->header('Authorization')[0] ?? null) === 'Bearer prv_test_key'
                && $request['payment_source_id'] === 3891
                && $request['recurrent'] === true
                && $request['payment_method']['installments'] === 1
                && $request['customer_email'] === 'user@example.com'
                && $request['signature'] === hash('sha256', 'VOI-REN-1-TEST3200000COPtest_integrity_key');
        });
    }

    #[Test]
    public function it_gets_wompi_payment_source_charge_status(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/transactions/trx_pending_1' => Http::response([
                'data' => [
                    'id' => 'trx_pending_1',
                    'reference' => 'VOI-TCV-1-TEST',
                    'status' => 'APPROVED',
                    'amount_in_cents' => 3200000,
                    'currency' => 'COP',
                ],
            ]),
        ]);

        $charge = $this->client()->getPaymentSourceCharge(
            providerTransactionId: 'trx_pending_1',
            reference: 'VOI-TCV-1-TEST',
            amountInCents: 3200000,
            currency: 'COP',
        );

        $this->assertTrue($charge->isSuccessful());
        $this->assertSame('approved', $charge->status);
        $this->assertSame('trx_pending_1', $charge->providerTransactionId);
        $this->assertSame('APPROVED', $charge->providerStatus);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://sandbox.wompi.co/v1/transactions/trx_pending_1'
                && ($request->header('Authorization')[0] ?? null) === 'Bearer prv_test_key';
        });
    }

    #[Test]
    public function it_marks_payment_source_charge_lookup_as_error_when_transaction_does_not_match(): void
    {
        Http::fake([
            'https://sandbox.wompi.co/v1/transactions/trx_pending_1' => Http::response([
                'data' => [
                    'id' => 'trx_pending_1',
                    'reference' => 'VOI-TCV-OTHER',
                    'status' => 'APPROVED',
                    'amount_in_cents' => 100,
                    'currency' => 'COP',
                ],
            ]),
        ]);

        $charge = $this->client()->getPaymentSourceCharge(
            providerTransactionId: 'trx_pending_1',
            reference: 'VOI-TCV-1-TEST',
            amountInCents: 3200000,
            currency: 'COP',
        );

        $this->assertFalse($charge->isSuccessful());
        $this->assertSame('error', $charge->status);
        $this->assertSame('APPROVED', $charge->providerStatus);
        $this->assertSame('transaction_mismatch', $charge->rawResponse['local_error']);
    }

    #[Test]
    public function it_requires_private_key_api_url_and_integrity_secret_for_payment_source_charges(): void
    {
        $client = new WompiPaymentClient(
            publicKey: 'pub_test_key',
            integritySecret: 'test_integrity_key',
            eventsSecret: 'test_events_key',
            privateKey: null,
            apiUrl: 'https://sandbox.wompi.co/v1',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Wompi private key, API URL and integrity secret are required.');

        $client->chargePaymentSource(new PaymentSourceChargeRequest(
            reference: 'VOI-REN-1-TEST',
            amountInCents: 3200000,
            currency: 'COP',
            customerEmail: 'user@example.com',
            paymentSourceProviderId: '3891',
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
            privateKey: 'prv_test_key',
            apiUrl: 'https://sandbox.wompi.co/v1',
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
