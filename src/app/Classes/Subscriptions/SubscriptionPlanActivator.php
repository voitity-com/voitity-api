<?php

namespace App\Classes\Subscriptions;

use App\Enums\PaymentOrderStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\PaymentOrder;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SubscriptionPlanActivator
{
    /**
     * @var array<string, string>
     */
    private const METRIC_COLUMNS = [
        'profiles' => 'profiles_remaining',
        'avatar_images' => 'avatar_images_remaining',
        'avatar_video_seconds' => 'avatar_video_seconds_remaining',
        'voice_clones' => 'voice_clones_remaining',
        'tts_characters' => 'tts_characters_remaining',
        'chat_messages' => 'chat_messages_remaining',
    ];

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

            $previousSubscription = $order->user
                ->subscriptions()
                ->where('active', true)
                ->orderByDesc('started_at')
                ->lockForUpdate()
                ->first();

            if ($previousSubscription) {
                $previousSubscription->status = SubscriptionStatus::Expired;
                $previousSubscription->active = false;
                $previousSubscription->save();
            }

            $startedAt = now();
            $renewsAt = $this->renewsAt($order->plan, $startedAt);

            /** @var Subscription $subscription */
            $subscription = $order->user->subscriptions()->create([
                'plan' => $order->plan,
                'started_at' => $startedAt,
                'renews_at' => $renewsAt,
                'status' => $previousSubscription ? SubscriptionStatus::Renewed : SubscriptionStatus::First,
                'active' => true,
            ]);

            $this->createLimit($subscription);

            $order->subscription_id = $subscription->id;
            $order->save();

            return $subscription;
        });
    }

    private function renewsAt(SubscriptionPlan $plan, Carbon $startedAt): Carbon
    {
        $interval = config("subscriptions.plans.{$plan->value}.interval", 'monthly');

        return match ($interval) {
            'yearly', 'annual', 'annually' => $startedAt->copy()->addYear(),
            default => $startedAt->copy()->addMonth(),
        };
    }

    private function createLimit(Subscription $subscription): SubscriptionLimit
    {
        $limits = config("subscriptions.plans.{$subscription->plan->value}.limits", []);
        $columns = [
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'period_started_at' => Carbon::parse($subscription->started_at),
            'period_renews_at' => Carbon::parse($subscription->renews_at),
            'credits_remaining' => round((float) config("subscriptions.plans.{$subscription->plan->value}.credits.total", 0), 2),
        ];

        foreach (self::METRIC_COLUMNS as $metric => $column) {
            $columns[$column] = max(0, (int) ($limits[$metric] ?? 0));
        }

        return SubscriptionLimit::create($columns);
    }
}
