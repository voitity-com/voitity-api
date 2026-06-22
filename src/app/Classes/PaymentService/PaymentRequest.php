<?php

namespace App\Classes\PaymentService;

use DateTimeInterface;

class PaymentRequest
{
    /**
     * @param  array<string, string>  $customerData
     */
    public function __construct(
        public readonly string $reference,
        public readonly int $amountInCents,
        public readonly string $currency,
        public readonly ?string $redirectUrl = null,
        public readonly ?DateTimeInterface $expirationTime = null,
        public readonly array $customerData = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reference' => $this->reference,
            'amount_in_cents' => $this->amountInCents,
            'currency' => $this->currency,
            'redirect_url' => $this->redirectUrl,
            'expiration_time' => $this->expirationTime?->format('Y-m-d\TH:i:s.v\Z'),
            'customer_data' => $this->customerData,
        ];
    }
}
