<?php

namespace App\Classes\Subscriptions;

use App\Enums\PaymentOrderStatus;
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

            $subscription = $this->subscriptionPlanAssigner->assign($order->user, $order->plan);

            $order->subscription_id = $subscription->id;
            $order->save();

            return $subscription;
        });
    }
}
