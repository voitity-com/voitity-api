<?php

namespace App\Http\Responses\Payments;

use App\Models\PaymentSource;

class PaymentMethodResponse
{
    public function __construct(private readonly PaymentSource $paymentSource) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $source = $this->paymentSource;

        return [
            'id' => $source->id,
            'provider' => $source->provider->value,
            'type' => $source->type,
            'brand' => $source->card_brand,
            'last_four' => $source->card_last_four,
            'expiration_month' => $source->card_exp_month,
            'expiration_year' => $source->card_exp_year,
            'status' => $source->status,
            'reusable' => (bool) $source->reusable,
            'is_default' => (bool) $source->is_default,
            'is_expired' => $source->isExpired(),
            'is_chargeable' => $source->isChargeable(),
            'requires_attention' => (bool) $source->requires_attention,
            'last_failure' => $source->requires_attention ? [
                'code' => $source->last_payment_failure_code,
                'failed_at' => $source->last_payment_failed_at?->toJSON(),
                'payment_order_id' => $source->last_failed_payment_order_id,
            ] : null,
            'verified_at' => $source->verified_at?->toJSON(),
            'last_used_at' => $source->last_used_at?->toJSON(),
            'created_at' => $source->created_at?->toJSON(),
        ];
    }
}
