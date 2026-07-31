<?php

namespace App\Http\Responses\Payments;

use App\Enums\PaymentProductType;
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
            'payment_source_id' => $order->payment_source_id,
            'provider' => $order->provider->value,
            'reference' => $order->reference,
            'provider_transaction_id' => $order->provider_transaction_id,
            'product_type' => ($order->product_type ?? PaymentProductType::Subscription)->value,
            'product_code' => $order->product_code,
            'credits' => \App\Classes\Subscriptions\CreditAmount::unitsToCredits((int) $order->credit_units),
            'plan' => $order->plan?->value,
            'recurring' => (bool) $order->recurring,
            'billing_reason' => $order->billing_reason,
            'customer_terms' => [
                'version' => $order->customer_terms_version,
                'accepted_at' => $order->customer_terms_accepted_at?->toJSON(),
                'accepted_plan_price_usd' => $order->accepted_plan_price_usd,
            ],
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
