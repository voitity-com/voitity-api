<?php

namespace App\Classes\PaymentService;

interface PaymentClient
{
    public function createPayment(PaymentRequest $request): PaymentIntent;

    public function paymentSourceSetup(): PaymentSourceSetup;

    public function createPaymentSource(PaymentSourceCreateRequest $request): PaymentSourceCreateResult;

    public function chargePaymentSource(PaymentSourceChargeRequest $request): PaymentSourceCharge;

    public function getPaymentSourceCharge(
        string $providerTransactionId,
        string $reference,
        int $amountInCents,
        string $currency,
    ): PaymentSourceCharge;

    /**
     * @param  array<string, mixed>  $headers
     */
    public function parseWebhook(array $headers, string $payload): PaymentWebhook;
}
