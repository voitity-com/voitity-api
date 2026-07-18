<?php

namespace App\Classes\PaymentService\Wompi;

use App\Classes\PaymentService\PaymentClient;
use App\Classes\PaymentService\PaymentIntent;
use App\Classes\PaymentService\PaymentRequest;
use App\Classes\PaymentService\PaymentSourceCreateRequest;
use App\Classes\PaymentService\PaymentSourceCreateResult;
use App\Classes\PaymentService\PaymentSourceCharge;
use App\Classes\PaymentService\PaymentSourceChargeRequest;
use App\Classes\PaymentService\PaymentSourceSetup;
use App\Classes\PaymentService\PaymentWebhook;
use DateTimeInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class WompiPaymentClient implements PaymentClient
{
    public function __construct(
        private readonly ?string $publicKey,
        private readonly ?string $integritySecret,
        private readonly ?string $eventsSecret,
        private readonly ?string $checkoutUrl = 'https://checkout.wompi.co/p/',
        private readonly ?string $widgetUrl = 'https://checkout.wompi.co/widget.js',
        private readonly ?string $privateKey = null,
        private readonly ?string $apiUrl = 'https://sandbox.wompi.co/v1',
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

    public function paymentSourceSetup(): PaymentSourceSetup
    {
        $this->ensurePaymentSourceSetupConfig();

        $requestUrl = $this->normalizedApiUrl().'/merchants/'.rawurlencode((string) $this->publicKey);
        $response = Http::acceptJson()->get($requestUrl);
        $responseData = $this->responseData($response);
        $data = is_array($responseData['data'] ?? null) ? $responseData['data'] : [];
        $acceptance = is_array($data['presigned_acceptance'] ?? null) ? $data['presigned_acceptance'] : [];
        $personalDataAuth = is_array($data['presigned_personal_data_auth'] ?? null)
            ? $data['presigned_personal_data_auth']
            : [];
        $acceptanceToken = $this->stringOrNull($acceptance['acceptance_token'] ?? null);
        $personalDataAuthToken = $this->stringOrNull($personalDataAuth['acceptance_token'] ?? null);

        if (! $response->successful() || ! $acceptanceToken || ! $personalDataAuthToken) {
            throw new InvalidArgumentException('Wompi acceptance tokens are required to create payment sources.');
        }

        return new PaymentSourceSetup(
            source: 'wompi',
            publicKey: (string) $this->publicKey,
            apiUrl: $this->normalizedApiUrl(),
            environment: $this->environment,
            acceptanceToken: $acceptanceToken,
            acceptancePermalink: $this->stringOrNull($acceptance['permalink'] ?? null),
            personalDataAuthToken: $personalDataAuthToken,
            personalDataAuthPermalink: $this->stringOrNull($personalDataAuth['permalink'] ?? null),
            rawResponse: $responseData,
        );
    }

    public function createPaymentSource(PaymentSourceCreateRequest $request): PaymentSourceCreateResult
    {
        $this->ensurePaymentSourceCreateConfig();

        $requestUrl = $this->normalizedApiUrl().'/payment_sources';
        $payload = [
            'type' => strtoupper($request->type),
            'token' => $request->token,
            'customer_email' => $request->customerEmail,
            'acceptance_token' => $request->acceptanceToken,
            'accept_personal_auth' => $request->acceptPersonalAuth,
        ];

        if ($request->sessionId) {
            $payload['session_id'] = $request->sessionId;
        }

        if ($request->customerData !== []) {
            $payload['customer_data'] = $request->customerData;
        }

        try {
            $response = Http::withToken((string) $this->privateKey)
                ->acceptJson()
                ->asJson()
                ->post($requestUrl, $payload);
            $responseData = $this->responseData($response);
            $paymentSource = is_array($responseData['data'] ?? null) ? $responseData['data'] : $responseData;
            $providerStatus = $this->stringOrNull($paymentSource['status'] ?? null);
            $status = $response->successful()
                ? self::normalizePaymentSourceStatus($providerStatus)
                : 'error';
            $publicData = is_array($paymentSource['public_data'] ?? null) ? $paymentSource['public_data'] : [];

            return new PaymentSourceCreateResult(
                source: 'wompi',
                providerSourceId: $this->stringOrNull($paymentSource['id'] ?? null),
                type: $this->stringOrNull($paymentSource['type'] ?? null) ?? strtoupper($request->type),
                providerStatus: $providerStatus,
                status: $status,
                reusable: in_array($status, ['active', 'pending'], true),
                httpStatus: $response->status(),
                requestUrl: $requestUrl,
                requestPayload: $this->redactedPaymentSourcePayload($payload),
                publicData: $publicData,
                rawResponse: $responseData,
            );
        } catch (Throwable $e) {
            return new PaymentSourceCreateResult(
                source: 'wompi',
                providerSourceId: null,
                type: strtoupper($request->type),
                providerStatus: null,
                status: 'error',
                reusable: false,
                requestUrl: $requestUrl,
                requestPayload: $this->redactedPaymentSourcePayload($payload),
                rawResponse: ['error' => $e->getMessage()],
            );
        }
    }

    public function chargePaymentSource(PaymentSourceChargeRequest $request): PaymentSourceCharge
    {
        $this->ensureChargeConfig();

        $requestUrl = $this->normalizedApiUrl().'/transactions';
        $signature = self::createIntegritySignature(
            reference: $request->reference,
            amountInCents: $request->amountInCents,
            currency: $request->currency,
            integritySecret: (string) $this->integritySecret,
        );
        $payload = [
            'amount_in_cents' => $request->amountInCents,
            'currency' => $request->currency,
            'signature' => $signature,
            'customer_email' => $request->customerEmail,
            'reference' => $request->reference,
            'payment_source_id' => $this->normalizedPaymentSourceId($request->paymentSourceProviderId),
            'recurrent' => $request->recurrent,
        ];

        if ($request->installments !== null) {
            $payload['payment_method'] = ['installments' => $request->installments];
        }

        try {
            $response = Http::withToken((string) $this->privateKey)
                ->acceptJson()
                ->asJson()
                ->post($requestUrl, $payload);
            $responseData = $this->responseData($response);
            $transaction = is_array($responseData['data'] ?? null) ? $responseData['data'] : $responseData;
            $providerStatus = $this->stringOrNull($transaction['status'] ?? null);
            $status = $response->successful()
                ? self::normalizeProviderStatus($providerStatus)
                : 'error';

            if ($response->successful() && ! $this->transactionMatchesExpected(
                $transaction,
                $request->reference,
                $request->amountInCents,
                $request->currency,
            )) {
                $status = 'error';
                $responseData['local_error'] = 'transaction_mismatch';
            }

            return new PaymentSourceCharge(
                source: 'wompi',
                reference: $request->reference,
                amountInCents: $request->amountInCents,
                currency: $request->currency,
                providerTransactionId: $this->stringOrNull($transaction['id'] ?? null),
                providerStatus: $providerStatus,
                status: $status,
                httpStatus: $response->status(),
                requestUrl: $requestUrl,
                requestPayload: $payload,
                rawResponse: $responseData,
            );
        } catch (Throwable $e) {
            return new PaymentSourceCharge(
                source: 'wompi',
                reference: $request->reference,
                amountInCents: $request->amountInCents,
                currency: $request->currency,
                providerTransactionId: null,
                providerStatus: null,
                status: 'error',
                requestUrl: $requestUrl,
                requestPayload: $payload,
                rawResponse: ['error' => $e->getMessage()],
            );
        }
    }

    public function getPaymentSourceCharge(
        string $providerTransactionId,
        string $reference,
        int $amountInCents,
        string $currency,
    ): PaymentSourceCharge {
        $this->ensureChargeConfig();

        $requestUrl = $this->normalizedApiUrl().'/transactions/'.rawurlencode($providerTransactionId);

        try {
            $response = Http::withToken((string) $this->privateKey)
                ->acceptJson()
                ->get($requestUrl);
            $responseData = $this->responseData($response);
            $transaction = is_array($responseData['data'] ?? null) ? $responseData['data'] : $responseData;
            $providerStatus = $this->stringOrNull($transaction['status'] ?? null);
            $status = $response->successful()
                ? self::normalizeProviderStatus($providerStatus)
                : 'error';

            if ($response->successful() && ! $this->transactionMatchesExpected(
                $transaction,
                $reference,
                $amountInCents,
                $currency,
            )) {
                $status = 'error';
                $responseData['local_error'] = 'transaction_mismatch';
            }

            return new PaymentSourceCharge(
                source: 'wompi',
                reference: $this->stringOrNull($transaction['reference'] ?? null) ?? $reference,
                amountInCents: $this->intOrNull($transaction['amount_in_cents'] ?? null) ?? $amountInCents,
                currency: $this->stringOrNull($transaction['currency'] ?? null) ?? $currency,
                providerTransactionId: $this->stringOrNull($transaction['id'] ?? null) ?? $providerTransactionId,
                providerStatus: $providerStatus,
                status: $status,
                httpStatus: $response->status(),
                requestUrl: $requestUrl,
                rawResponse: $responseData,
            );
        } catch (Throwable $e) {
            return new PaymentSourceCharge(
                source: 'wompi',
                reference: $reference,
                amountInCents: $amountInCents,
                currency: $currency,
                providerTransactionId: $providerTransactionId,
                providerStatus: null,
                status: 'error',
                requestUrl: $requestUrl,
                rawResponse: ['error' => $e->getMessage()],
            );
        }
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
            paymentSourceProviderId: $this->paymentSourceIdFromPayload($decodedPayload, $transaction),
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

    public static function normalizePaymentSourceStatus(?string $status): string
    {
        return match (strtoupper((string) $status)) {
            'ACTIVE', 'APPROVED', 'AVAILABLE' => 'active',
            'DECLINED' => 'declined',
            'VOIDED' => 'voided',
            'ERROR' => 'error',
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

    private function normalizedApiUrl(): string
    {
        return rtrim($this->apiUrl ?: 'https://sandbox.wompi.co/v1', '/');
    }

    private function ensureCheckoutConfig(): void
    {
        if (! $this->publicKey || ! $this->integritySecret) {
            throw new InvalidArgumentException('Wompi public key and integrity secret are required.');
        }
    }

    private function ensurePaymentSourceSetupConfig(): void
    {
        if (! $this->publicKey || ! $this->apiUrl) {
            throw new InvalidArgumentException('Wompi public key and API URL are required.');
        }
    }

    private function ensurePaymentSourceCreateConfig(): void
    {
        if (! $this->privateKey || ! $this->apiUrl) {
            throw new InvalidArgumentException('Wompi private key and API URL are required.');
        }
    }

    private function ensureChargeConfig(): void
    {
        if (! $this->privateKey || ! $this->integritySecret || ! $this->apiUrl) {
            throw new InvalidArgumentException('Wompi private key, API URL and integrity secret are required.');
        }
    }

    private function formatExpirationTime(?DateTimeInterface $expirationTime): ?string
    {
        return $expirationTime?->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * @return array<string, mixed>
     */
    private function responseData(Response $response): array
    {
        $data = $response->json();

        if (is_array($data)) {
            return $data;
        }

        return ['raw' => $response->body()];
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

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $transaction
     */
    private function paymentSourceIdFromPayload(array $payload, array $transaction): ?string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $paymentSource = is_array($data['payment_source'] ?? null) ? $data['payment_source'] : [];
        $transactionPaymentSource = is_array($transaction['payment_source'] ?? null)
            ? $transaction['payment_source']
            : [];

        return $this->stringOrNull($transaction['payment_source_id'] ?? null)
            ?? $this->stringOrNull($transactionPaymentSource['id'] ?? null)
            ?? $this->stringOrNull($paymentSource['id'] ?? null);
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

    private function normalizedPaymentSourceId(string $paymentSourceProviderId): int|string
    {
        return ctype_digit($paymentSourceProviderId) ? (int) $paymentSourceProviderId : $paymentSourceProviderId;
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    private function transactionMatchesExpected(array $transaction, string $reference, int $amountInCents, string $currency): bool
    {
        $transactionReference = $this->stringOrNull($transaction['reference'] ?? null);

        if ($transactionReference !== null && $transactionReference !== $reference) {
            return false;
        }

        $transactionAmount = $this->intOrNull($transaction['amount_in_cents'] ?? null);

        if ($transactionAmount !== null && $transactionAmount !== $amountInCents) {
            return false;
        }

        $transactionCurrency = $this->stringOrNull($transaction['currency'] ?? null);

        return $transactionCurrency === null || $transactionCurrency === $currency;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function redactedPaymentSourcePayload(array $payload): array
    {
        if (isset($payload['token'])) {
            $payload['token'] = '[redacted]';
        }

        if (isset($payload['acceptance_token'])) {
            $payload['acceptance_token'] = '[redacted]';
        }

        if (isset($payload['accept_personal_auth'])) {
            $payload['accept_personal_auth'] = '[redacted]';
        }

        return $payload;
    }
}
