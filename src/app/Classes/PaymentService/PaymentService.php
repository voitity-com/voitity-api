<?php

namespace App\Classes\PaymentService;

class PaymentService
{
    public function __construct(private readonly PaymentClient $paymentClient) {}

    public function createPayment(PaymentRequest $request): PaymentIntent
    {
        return $this->paymentClient->createPayment($request);
    }

    public function paymentSourceSetup(): PaymentSourceSetup
    {
        return $this->paymentClient->paymentSourceSetup();
    }

    public function createPaymentSource(PaymentSourceCreateRequest $request): PaymentSourceCreateResult
    {
        return $this->paymentClient->createPaymentSource($request);
    }

    public function chargePaymentSource(PaymentSourceChargeRequest $request): PaymentSourceCharge
    {
        return $this->paymentClient->chargePaymentSource($request);
    }

    public function getPaymentSourceCharge(
        string $providerTransactionId,
        string $reference,
        int $amountInCents,
        string $currency,
    ): PaymentSourceCharge {
        return $this->paymentClient->getPaymentSourceCharge($providerTransactionId, $reference, $amountInCents, $currency);
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    public function parseWebhook(array $headers, string $payload): PaymentWebhook
    {
        return $this->paymentClient->parseWebhook($headers, $payload);
    }

    public function getPaymentClient(): PaymentClient
    {
        return $this->paymentClient;
    }
}
