<?php

namespace App\Classes\PaymentService;

interface PaymentClient
{
    public function createPayment(PaymentRequest $request): PaymentIntent;

    public function chargePaymentSource(PaymentSourceChargeRequest $request): PaymentSourceCharge;

    /**
     * @param  array<string, mixed>  $headers
     */
    public function parseWebhook(array $headers, string $payload): PaymentWebhook;
}
