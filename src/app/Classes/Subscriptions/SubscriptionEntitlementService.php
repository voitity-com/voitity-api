<?php

namespace App\Classes\Subscriptions;

use App\Enums\SubscriptionPlan;
use App\Exceptions\Subscriptions\SubscriptionEntitlementException;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Log;

class SubscriptionEntitlementService
{
    private const CREDIT_PRECISION = 6;

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
        'incoming_audio_messages' => 'incoming_audio_messages_remaining',
        'incoming_audio_seconds' => 'incoming_audio_seconds_remaining',
    ];

    public function __construct(
        private readonly SubscriptionPlanCatalog $planCatalog,
        private readonly SubscriptionRenewalService $renewalService,
        private readonly SubscriptionLimitPeriodService $limitPeriods,
        private readonly SubscriptionProfileAccessService $profileAccess,
    ) {}

    /**
     * @param  array<string, int>  $amounts
     */
    public function assertCanUse(User|int $user, array $amounts): Subscription
    {
        $subscription = $this->assertHasActiveSubscription($user);

        if ($this->planCatalog->isUnlimited($subscription->plan)) {
            return $subscription;
        }

        $limit = $this->limitPeriods->syncCurrentPeriod($subscription);

        if (! $limit instanceof SubscriptionLimit) {
            throw new SubscriptionEntitlementException(
                'Subscription limits were not found.',
                ['subscription' => ['Subscription limits were not found.']]
            );
        }

        $normalizedAmounts = $this->normalizeAmounts($amounts);
        $errors = $this->capacityErrors($subscription->plan, $limit, $normalizedAmounts);

        if ($errors !== []) {
            $this->notifyLimitReached($subscription, $errors);

            throw new SubscriptionEntitlementException('Subscription limit exceeded.', $errors);
        }

        return $subscription;
    }

    public function assertHasActiveSubscription(User|int $user): Subscription
    {
        $userId = $user instanceof User ? $user->id : $user;

        try {
            $subscription = $this->activeSubscriptionFor((int) $userId);
        } catch (SubscriptionEntitlementException $exception) {
            $this->profileAccess->deactivateProfilesIfAccessEnded(
                (int) $userId,
                'active_subscription_not_found'
            );

            throw $exception;
        }

        $subscription = $this->renewalService->renewIfFree($subscription);

        if ($subscription->renews_at->isPast()) {
            $subscription->status = $subscription->cancel_at_period_end
                ? \App\Enums\SubscriptionStatus::Cancelled
                : \App\Enums\SubscriptionStatus::Expired;
            $subscription->active = false;
            $subscription->save();
            $this->profileAccess->deactivateProfilesIfAccessEnded(
                (int) $userId,
                'subscription_expired_during_entitlement_check',
                $subscription->id
            );
            $this->notifySubscriptionDeactivated($subscription);

            Log::warning('Expired subscription rejected during entitlement check.', [
                'plan' => $subscription->plan->value,
                'subscription_id' => $subscription->id,
                'user_id' => $userId,
            ]);

            throw new SubscriptionEntitlementException(
                'Active subscription has expired.',
                ['subscription' => ['Active subscription has expired.']]
            );
        }

        return $subscription;
    }

    private function activeSubscriptionFor(int $userId): Subscription
    {
        $subscription = Subscription::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->with('limit', 'user')
            ->latest('started_at')
            ->first();

        if (! $subscription instanceof Subscription) {
            throw new SubscriptionEntitlementException(
                'Active subscription not found.',
                ['subscription' => ['Active subscription not found.']]
            );
        }

        return $subscription;
    }

    /**
     * @param  array<string, int>  $amounts
     * @return array<string, int>
     */
    private function normalizeAmounts(array $amounts): array
    {
        $normalized = [];

        foreach (self::METRIC_COLUMNS as $metric => $column) {
            $amount = max(0, (int) ($amounts[$metric] ?? 0));

            if ($amount > 0) {
                $normalized[$metric] = $amount;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, int>  $amounts
     * @return array<string, list<string>>
     */
    private function capacityErrors(SubscriptionPlan $plan, SubscriptionLimit $limit, array $amounts): array
    {
        $errors = [];

        foreach ($amounts as $metric => $amount) {
            $column = self::METRIC_COLUMNS[$metric];
            $remaining = (int) $limit->{$column};

            if ($remaining < $amount) {
                $errors[$metric] = ["Insufficient {$metric} quota."];
            }
        }

        $creditsUsed = $this->creditsUsedForPlan($plan, $amounts);

        if ($creditsUsed > 0 && (float) $limit->credits_remaining < $creditsUsed) {
            $errors['credits'] = ['Insufficient credits quota.'];
        }

        return $errors;
    }

    /**
     * @param  array<string, int>  $amounts
     */
    private function creditsUsedForPlan(SubscriptionPlan $plan, array $amounts): float
    {
        $allocations = config("subscriptions.plans.{$plan->value}.credits.allocations", []);
        $creditsUsed = 0.0;

        foreach ($amounts as $metric => $amount) {
            if ($amount <= 0 || ! isset($allocations[$metric])) {
                continue;
            }

            $credits = (float) ($allocations[$metric]['credits'] ?? 0);
            $units = (float) ($allocations[$metric]['units'] ?? 0);

            if ($credits <= 0 || $units <= 0) {
                continue;
            }

            $creditsUsed += $amount * ($credits / $units);
        }

        return round($creditsUsed, self::CREDIT_PRECISION);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function notifyLimitReached(Subscription $subscription, array $errors): void
    {
        $subscription->loadMissing('user');

        if (! $subscription->user instanceof User) {
            return;
        }

        $metric = (string) array_key_first($errors);
        $dispatcher = app(NotificationDispatcher::class);
        $specificKey = $this->limitNotificationKey($metric);

        if ($specificKey) {
            $dispatcher->sendInApp($subscription->user, $specificKey, [
                'metric' => $metric,
                'plan' => $subscription->plan->value,
            ]);

            $dispatcher->sendEmail($subscription->user, 'critical_plan_limit_reached', [
                'metric' => $metric,
                'plan' => $subscription->plan->value,
            ]);

            return;
        }

        $dispatcher->send($subscription->user, 'critical_plan_limit_reached', [
            'metric' => $metric,
            'plan' => $subscription->plan->value,
        ]);
    }

    private function limitNotificationKey(string $metric): ?string
    {
        return match ($metric) {
            'profiles' => 'profile_limit_reached',
            'avatar_images', 'avatar_video_seconds' => 'avatar_limit_reached',
            'voice_clones', 'tts_characters' => 'voice_limit_reached',
            'chat_messages', 'incoming_audio_messages', 'incoming_audio_seconds' => 'message_or_chat_limit_reached',
            default => null,
        };
    }

    private function notifySubscriptionDeactivated(Subscription $subscription): void
    {
        $subscription->loadMissing('user');

        if (! $subscription->user instanceof User) {
            return;
        }

        app(NotificationDispatcher::class)->send($subscription->user, 'subscription_cancelled_or_deactivated', [
            'plan' => $subscription->plan->value,
            'subscription_id' => $subscription->id,
        ]);
    }
}
