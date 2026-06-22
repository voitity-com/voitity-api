<?php

namespace App\Classes\PaymentService\Wompi;

use App\Classes\PaymentService\PaymentClient;
use App\Classes\PaymentService\PaymentIntent;
use App\Classes\PaymentService\PaymentRequest;
use App\Classes\PaymentService\PaymentWebhook;
use DateTimeInterface;
use InvalidArgumentException;

class WompiPaymentClient implements PaymentClient
{
    public function __construct(
        private readonly ?string $publicKey,
        private readonly ?string $integritySecret,
        private readonly ?string $eventsSecret,
        private readonly ?string $checkoutUrl = 'https://checkout.wompi.co/p/',
        private readonly ?string $widgetUrl = 'https://checkout.wompi.co/widget.js',
        private readonly ?string $environment = 'sandbox',
    ) {}

    public function createPayment(PaymentRequest $request): PaymentIntent
    {
        $this->ensureCheckoutConfig();

        $expirationTime = $this->formatExpirationTime($request->expirationTime);
        $signature = self::createIntegritySignature(
            reference: $request->reference,
            amountInCents: $request->amountInCents,
            currency: $request->currency,
            integritySecret: (string) $this->integritySecret,
            expirationTime: $expirationTime,
        );

        $parameters = [
            'public-key' => (string) $this->publicKey,
            'currency' => $request->currency,
            'amount-in-cents' => (string) $request->amountInCents,
            'reference' => $request->reference,
            'signature:integrity' => $signature,
        ];

        if ($request->redirectUrl) {
            $parameters['redirect-url'] = $request->redirectUrl;
        }

        if ($expirationTime) {
            $parameters['expiration-time'] = $expirationTime;
        }

        foreach ($request->customerData as $key => $value) {
            $parameters["customer-data:{$key}"] = $value;
        }

        $checkoutUrl = $this->buildCheckoutUrl($parameters);
        $widgetConfig = [
            'publicKey' => (string) $this->publicKey,
            'currency' => $request->currency,
            'amountInCents' => (string) $request->amountInCents,
            'reference' => $request->reference,
            'signature' => ['integrity' => $signature],
        ];

        if ($request->redirectUrl) {
            $widgetConfig['redirectUrl'] = $request->redirectUrl;
        }

        if ($expirationTime) {
            $widgetConfig['expirationTime'] = $expirationTime;
        }

        return new PaymentIntent(
            source: 'wompi',
            reference: $request->reference,
            amountInCents: $request->amountInCents,
            currency: $request->currency,
            publicKey: (string) $this->publicKey,
            integritySignature: $signature,
            checkoutUrl: $checkoutUrl,
            widgetUrl: $this->normalizedWidgetUrl(),
            formParameters: $parameters,
            widgetConfig: $widgetConfig,
            redirectUrl: $request->redirectUrl,
            expirationTime: $expirationTime,
            rawResponse: [
                'environment' => $this->environment,
                'checkout_url' => $checkoutUrl,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public function parseWebhook(array $headers, string $payload): PaymentWebhook
    {
        $decodedPayload = json_decode($payload, true);

        if (! is_array($decodedPayload)) {
            return new PaymentWebhook(
                source: 'wompi',
                event: 'invalid_payload',
                providerEventId: hash('sha256', $payload),
                isValidSignature: false,
                checksum: null,
                calculatedChecksum: null,
                reference: null,
                providerTransactionId: null,
                amountInCents: null,
                currency: null,
                providerStatus: null,
                status: 'error',
                payload: ['raw' => $payload],
            );
        }

        $event = (string) ($decodedPayload['event'] ?? 'unknown');
        $signature = is_array($decodedPayload['signature'] ?? null) ? $decodedPayload['signature'] : [];
        $properties = is_array($signature['properties'] ?? null) ? $signature['properties'] : [];
        $checksum = $this->extractChecksum($headers, $signature);
        $calculatedChecksum = $this->calculateEventChecksum($decodedPayload, $properties);
        $isValidSignature = $checksum !== null
            && $calculatedChecksum !== null
            && hash_equals(strtolower($checksum), strtolower($calculatedChecksum));
        $transaction = $this->transactionFromPayload($decodedPayload);
        $providerStatus = $this->stringOrNull($transaction['status'] ?? null);
        $providerEventId = $this->stringOrNull($decodedPayload['id'] ?? null) ?? hash('sha256', $payload);

        return new PaymentWebhook(
            source: 'wompi',
            event: $event,
            providerEventId: $providerEventId,
            isValidSignature: $isValidSignature,
            checksum: $checksum,
            calculatedChecksum: $calculatedChecksum,
            reference: $this->stringOrNull($transaction['reference'] ?? null),
            providerTransactionId: $this->stringOrNull($transaction['id'] ?? null),
            amountInCents: $this->intOrNull($transaction['amount_in_cents'] ?? null),
            currency: $this->stringOrNull($transaction['currency'] ?? null),
            providerStatus: $providerStatus,
            status: self::normalizeProviderStatus($providerStatus),
            payload: $decodedPayload,
            transaction: $transaction,
        );
    }

    public static function createIntegritySignature(
        string $reference,
        int $amountInCents,
        string $currency,
        string $integritySecret,
        ?string $expirationTime = null,
    ): string {
        $payload = $reference.$amountInCents.$currency;

        if ($expirationTime) {
            $payload .= $expirationTime;
        }

        return hash('sha256', $payload.$integritySecret);
    }

    public static function normalizeProviderStatus(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'APPROVED' => 'approved',
            'DECLINED' => 'declined',
            'VOIDED' => 'voided',
            'ERROR' => 'error',
            'EXPIRED' => 'expired',
            default => 'pending',
        };
    }

    /**
     * @param  array<string, string>  $parameters
     */
    private function buildCheckoutUrl(array $parameters): string
    {
        $baseUrl = rtrim($this->checkoutUrl ?: 'https://checkout.wompi.co/p/', '/').'/';

        return $baseUrl.'?'.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    private function normalizedWidgetUrl(): string
    {
        return $this->widgetUrl ?: 'https://checkout.wompi.co/widget.js';
    }

    private function ensureCheckoutConfig(): void
    {
        if (! $this->publicKey || ! $this->integritySecret) {
            throw new InvalidArgumentException('Wompi public key and integrity secret are required.');
        }
    }

    private function formatExpirationTime(?DateTimeInterface $expirationTime): ?string
    {
        return $expirationTime?->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, mixed>  $properties
     */
    private function calculateEventChecksum(array $payload, array $properties): ?string
    {
        if (! $this->eventsSecret || ! isset($payload['timestamp'])) {
            return null;
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $checksumPayload = '';

        foreach ($properties as $property) {
            if (! is_string($property)) {
                return null;
            }

            $value = $this->getDotValue($data, $property);

            if ($value === null || is_array($value) || is_object($value)) {
                return null;
            }

            $checksumPayload .= (string) $value;
        }

        $checksumPayload .= (string) $payload['timestamp'];
        $checksumPayload .= $this->eventsSecret;

        return hash('sha256', $checksumPayload);
    }

    /**
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $signature
     */
    private function extractChecksum(array $headers, array $signature): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== 'x-event-checksum') {
                continue;
            }

            if (is_array($value)) {
                return $this->stringOrNull($value[0] ?? null);
            }

            return $this->stringOrNull($value);
        }

        return $this->stringOrNull($signature['checksum'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function getDotValue(array $data, string $path): mixed
    {
        $value = $data;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function transactionFromPayload(array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return is_array($data['transaction'] ?? null) ? $data['transaction'] : [];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $stringValue = trim((string) $value);

        return $stringValue === '' ? null : $stringValue;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
