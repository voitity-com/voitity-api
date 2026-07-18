<?php

namespace App\Classes\PaymentService;

class PaymentSourceCreateRequest
{
    /**
     * @param  array<string, mixed>  $customerData
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $customerEmail,
        public readonly string $type,
        public readonly string $token,
        public readonly string $acceptanceToken,
        public readonly string $acceptPersonalAuth,
        public readonly ?string $sessionId = null,
        public readonly array $customerData = [],
        public readonly array $metadata = [],
    ) {}
}
