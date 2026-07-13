<?php

namespace App\Classes\Subscriptions;

use App\Enums\PaymentOrderStatus;
use App\Enums\SubscriptionStatus;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SubscriptionPlanActivator
{
    public function __construct(private readonly SubscriptionPlanAssigner $subscriptionPlanAssigner) {}

    public function activateForPaymentOrder(PaymentOrder $paymentOrder): Subscription
    {
        return DB::transaction(function () use ($paymentOrder) {
            /** @var PaymentOrder $order */
            $order = PaymentOrder::whereKey($paymentOrder->id)->lockForUpdate()->firstOrFail();

            if ($order->subscription_id) {
                return $order->subscription()->firstOrFail();
            }

            if ($order->status !== PaymentOrderStatus::Approved) {
                throw new RuntimeException('Only approved payment orders can activate subscriptions.');
            }

            $trialSubscription = $order->billing_reason === 'trial_conversion'
                ? $order->user
                    ->subscriptions()
                    ->where('active', true)
                    ->where('status', SubscriptionStatus::Trialing->value)
                    ->lockForUpdate()
                    ->latest('started_at')
                    ->first()
                : null;

            $subscription = $this->subscriptionPlanAssigner->assign($order->user, $order->plan, [
                'payment_source_id' => $order->payment_source_id,
                'source_payment_order_id' => $order->id,
                'billing_mode' => $order->recurring ? 'recurring' : 'one_time',
                'last_billed_at' => $order->paid_at ?? now(),
            ]);

            if ($trialSubscription instanceof Subscription) {
                $trialSubscription->trial_converted_at = $order->paid_at ?? now();
                $trialSubscription->save();
            }

            $order->subscription_id = $subscription->id;
            $order->save();

            return $subscription;
        });
    }
}
