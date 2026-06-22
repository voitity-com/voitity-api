<?php

namespace App\Http\Responses\Payments;

use App\Models\PaymentOrder;

class PaymentOrderResponse
{
    public function __construct(private readonly PaymentOrder $paymentOrder) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $order = $this->paymentOrder;

        return [
            'id' => $order->id,
            'user_id' => $order->user_id,
            'subscription_id' => $order->subscription_id,
            'provider' => $order->provider->value,
            'reference' => $order->reference,
            'provider_transaction_id' => $order->provider_transaction_id,
            'plan' => $order->plan->value,
            'amounts' => [
                'display_amount_usd' => $order->display_amount_usd,
                'display_currency' => $order->display_currency->value,
                'exchange_rate' => $order->exchange_rate,
                'amount_cop' => $order->amount_cop,
                'amount_in_cents' => $order->amount_in_cents,
                'currency' => $order->currency->value,
            ],
            'status' => $order->status->value,
            'wompi_status' => $order->wompi_status,
            'checkout_url' => $order->checkout_url,
            'paid_at' => $order->paid_at?->toJSON(),
            'expires_at' => $order->expires_at?->toJSON(),
            'created_at' => $order->created_at?->toJSON(),
            'updated_at' => $order->updated_at?->toJSON(),
        ];
    }
}
