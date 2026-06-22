<?php

namespace App\Classes\PaymentService;

class PaymentService
{
    public function __construct(private readonly PaymentClient $paymentClient) {}

    public function createPayment(PaymentRequest $request): PaymentIntent
    {
        return $this->paymentClient->createPayment($request);
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
