<?php

namespace App\Classes\PaymentService;

class PaymentSourceChargeRequest
{
    public function __construct(
        public readonly string $reference,
        public readonly int $amountInCents,
        public readonly string $currency,
        public readonly string $customerEmail,
        public readonly string $paymentSourceProviderId,
        public readonly ?int $installments = 1,
        public readonly bool $recurrent = true,
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
            'customer_email' => $this->customerEmail,
            'payment_source_provider_id' => $this->paymentSourceProviderId,
            'installments' => $this->installments,
            'recurrent' => $this->recurrent,
        ];
    }
}
