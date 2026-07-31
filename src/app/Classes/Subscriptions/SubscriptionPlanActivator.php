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
    public function __construct(
        private readonly SubscriptionPlanAssigner $subscriptionPlanAssigner,
        private readonly PaymentMethodService $paymentMethods,
        private readonly ?SubscriptionProfileAccessService $profileAccess = null,
    ) {}

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

            $sourceSubscription = $order->source_subscription_id
                ? Subscription::query()
                    ->whereKey($order->source_subscription_id)
                    ->lockForUpdate()
                    ->first()
                : null;
            $trialSubscription = $order->billing_reason === 'trial_conversion'
                ? ($sourceSubscription ?? $order->user
                    ->subscriptions()
                    ->where('active', true)
                    ->where('status', SubscriptionStatus::Trialing->value)
                    ->lockForUpdate()
                    ->latest('started_at')
                    ->first())
                : null;

            $subscription = $this->subscriptionPlanAssigner->assign($order->user, $order->plan, [
                'payment_source_id' => $order->payment_source_id,
                'source_payment_order_id' => $order->id,
                'billing_mode' => $order->recurring ? 'recurring' : 'one_time',
                'last_billed_at' => $order->paid_at ?? now(),
                'status' => $sourceSubscription instanceof Subscription
                    ? SubscriptionStatus::Renewed
                    : SubscriptionStatus::First,
            ]);

            if ($trialSubscription instanceof Subscription) {
                $trialSubscription->trial_converted_at = $order->paid_at ?? now();
                $trialSubscription->save();
            }

            if ($sourceSubscription instanceof Subscription) {
                $wasPaymentRecovery = $sourceSubscription->access_ended_reason === 'payment_failure'
                    || filled($sourceSubscription->payment_failure_code);
                $sourceSubscription->forceFill([
                    'active' => false,
                    'status' => SubscriptionStatus::Expired,
                    'next_payment_retry_at' => null,
                ])->save();

                if ($wasPaymentRecovery) {
                    $this->profileAccess()->restoreProfilesAfterPaymentRecovery(
                        $subscription,
                        $sourceSubscription->id,
                    );
                }
            }

            $order->subscription_id = $subscription->id;
            $order->save();

            if ($order->paymentSource) {
                $this->paymentMethods->markDefaultAfterApprovedPayment($order->paymentSource);
            }

            return $subscription;
        });
    }

    private function profileAccess(): SubscriptionProfileAccessService
    {
        return $this->profileAccess ?? app(SubscriptionProfileAccessService::class);
    }
}
