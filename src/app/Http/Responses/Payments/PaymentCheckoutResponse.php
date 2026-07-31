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
        $checkout = $this->paymentIntent->toArray();
        unset($checkout['raw_response']);

        return [
            'payment_order' => (new PaymentOrderResponse($this->paymentOrder))->toArray(),
            'checkout' => $checkout,
        ];
    }
}
