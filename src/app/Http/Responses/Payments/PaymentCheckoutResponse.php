<?php

namespace App\Http\Responses\Payments;

use App\Classes\PaymentService\PaymentIntent;
use App\Models\PaymentOrder;

class PaymentCheckoutResponse
{
    public function __construct(
        private readonly PaymentOrder $paymentOrder,
        private readonly PaymentIntent $paymentIntent,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'payment_order' => (new PaymentOrderResponse($this->paymentOrder))->toArray(),
            'checkout' => $this->paymentIntent->toArray(),
        ];
    }
}
