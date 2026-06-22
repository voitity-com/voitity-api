<?php

namespace App\Classes\PaymentService;

interface PaymentClient
{
    public function createPayment(PaymentRequest $request): PaymentIntent;

    /**
     * @param  array<string, mixed>  $headers
     */
    public function parseWebhook(array $headers, string $payload): PaymentWebhook;
}
