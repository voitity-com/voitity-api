<?php

namespace App\Classes\PaymentService;

class PaymentIntent
{
    /**
     * @param  array<string, string>  $formParameters
     * @param  array<string, string>  $widgetConfig
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public readonly string $source,
        public readonly string $reference,
        public readonly int $amountInCents,
        public readonly string $currency,
        public readonly string $publicKey,
        public readonly string $integritySignature,
        public readonly string $checkoutUrl,
        public readonly string $widgetUrl,
        public readonly array $formParameters,
        public readonly array $widgetConfig,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $expirationTime = null,
        public readonly string $status = 'pending',
        public readonly array $rawResponse = [],
    ) {}

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isSuccessful(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'reference' => $this->reference,
            'amount_in_cents' => $this->amountInCents,
            'currency' => $this->currency,
            'public_key' => $this->publicKey,
            'integrity_signature' => $this->integritySignature,
            'checkout_url' => $this->checkoutUrl,
            'widget_url' => $this->widgetUrl,
            'form_parameters' => $this->formParameters,
            'widget_config' => $this->widgetConfig,
            'redirect_url' => $this->redirectUrl,
            'expiration_time' => $this->expirationTime,
            'status' => $this->status,
            'raw_response' => $this->rawResponse,
        ];
    }
}
