<?php

namespace App\Classes\Subscriptions;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionUsageType;
use App\Exceptions\Subscriptions\SubscriptionEntitlementException;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use App\Models\SubscriptionUse;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionUsageRecorder
{
    /**
     * @var array<string, array{limit: string, use: string}>
     */
    private const METRIC_COLUMNS = [
        'profiles' => [
            'limit' => 'profiles_remaining',
            'use' => 'profiles_used',
        ],
        'avatar_images' => [
            'limit' => 'avatar_images_remaining',
            'use' => 'avatar_images_used',
        ],
        'avatar_video_seconds' => [
            'limit' => 'avatar_video_seconds_remaining',
            'use' => 'avatar_video_seconds_used',
        ],
        'voice_clones' => [
            'limit' => 'voice_clones_remaining',
            'use' => 'voice_clones_used',
        ],
        'tts_characters' => [
            'limit' => 'tts_characters_remaining',
            'use' => 'tts_characters_used',
        ],
        'chat_messages' => [
            'limit' => 'chat_messages_remaining',
            'use' => 'chat_messages_used',
        ],
    ];

    public function __construct(private readonly ?SubscriptionLimitPeriodService $limitPeriods = null) {}

    /**
     * @param  array<string, int>  $amounts
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        int $userId,
        SubscriptionUsageType $usageType,
        array $amounts,
        string $idempotencyKey,
        ?int $profileId = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        array $metadata = []
    ): SubscriptionUse {
        $existingUse = SubscriptionUse::where('idempotency_key', $idempotencyKey)->first();

        if ($existingUse) {
            return $existingUse;
        }

        try {
            $use = DB::transaction(function () use (
                $userId,
                $usageType,
                $amounts,
                $idempotencyKey,
                $profileId,
                $sourceType,
                $sourceId,
                $metadata
            ) {
                $existingUse = SubscriptionUse::where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingUse) {
                    return $existingUse;
                }

                /** @var User $user */
                $user = User::whereKey($userId)->lockForUpdate()->firstOrFail();
                $subscription = $this->currentSubscriptionFor($user);
                $limit = $this->currentLimitFor($subscription);
                $normalizedAmounts = $this->normalizeAmounts($amounts);
                $creditsUsed = $this->creditsUsedForPlan($subscription->plan, $normalizedAmounts);
                $unlimited = (bool) config("subscriptions.plans.{$subscription->plan->value}.unlimited", false);

                if (! $unlimited) {
                    $this->assertLimitCanRecord($limit, $normalizedAmounts, $creditsUsed);
                }

                $use = SubscriptionUse::create(array_merge([
                    'subscription_id' => $subscription->id,
                    'user_id' => $user->id,
                    'profile_id' => $profileId,
                    'usage_type' => $usageType,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'idempotency_key' => $idempotencyKey,
                    'metadata' => $metadata,
                    'used_at' => now(),
                ], $this->usageColumns($normalizedAmounts, $creditsUsed)));

                if (! $unlimited) {
                    foreach ($normalizedAmounts as $metric => $amount) {
                        $limitColumn = self::METRIC_COLUMNS[$metric]['limit'];
                        $limit->{$limitColumn} = max(0, ((int) $limit->{$limitColumn}) - $amount);
                    }

                    $limit->credits_remaining = round(max(0, ((float) $limit->credits_remaining) - $creditsUsed), 2);
                    $limit->save();
                }

                return $use;
            });

            $this->notifyUsageUpdated($use);

            return $use;
        } catch (SubscriptionEntitlementException $exception) {
            if ($exception->getMessage() === 'Active subscription has expired.') {
                $this->expireDueSubscriptionsFor((int) $userId);
            }

            throw $exception;
        }
    }

    public function release(string $idempotencyKey): bool
    {
        return DB::transaction(function () use ($idempotencyKey): bool {
            /** @var SubscriptionUse|null $use */
            $use = SubscriptionUse::where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if (! $use) {
                return false;
            }

            /** @var Subscription|null $subscription */
            $subscription = Subscription::whereKey($use->subscription_id)
                ->lockForUpdate()
                ->first();
            /** @var SubscriptionLimit|null $limit */
            $limit = SubscriptionLimit::where('subscription_id', $use->subscription_id)
                ->lockForUpdate()
                ->first();
            $unlimited = $subscription
                ? (bool) config("subscriptions.plans.{$subscription->plan->value}.unlimited", false)
                : false;

            if ($subscription && $limit && ! $unlimited && $this->useBelongsToLimitPeriod($use, $limit)) {
                foreach (self::METRIC_COLUMNS as $metric => $columns) {
                    $used = max(0, (int) $use->{$columns['use']});

                    if ($used <= 0) {
                        continue;
                    }

                    $limit->{$columns['limit']} = min(
                        $this->includedLimitFor($subscription, $metric),
                        ((int) $limit->{$columns['limit']}) + $used
                    );
                }

                $limit->credits_remaining = min(
                    $this->includedCreditsFor($subscription),
                    round(((float) $limit->credits_remaining) + ((float) $use->credits_used), 2)
                );
                $limit->save();
            }

            $use->delete();

            return true;
        });
    }

    private function currentSubscriptionFor(User $user): Subscription
    {
        /** @var Subscription|null $subscription */
        $subscription = $user->subscriptions()
            ->where('active', true)
            ->orderByDesc('started_at')
            ->lockForUpdate()
            ->first();

        if ($subscription) {
            if ($subscription->renews_at->isFuture()) {
                return $subscription;
            }

            $subscription->status = SubscriptionStatus::Expired;
            $subscription->active = false;
            $subscription->save();
            $this->notifySubscriptionDeactivated($subscription);

            throw new SubscriptionEntitlementException(
                'Active subscription has expired.',
                ['subscription' => ['Active subscription has expired.']]
            );
        }

        throw new SubscriptionEntitlementException(
            'Active subscription not found.',
            ['subscription' => ['Active subscription not found.']]
        );
    }

    private function expireDueSubscriptionsFor(int $userId): void
    {
        Subscription::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->where('renews_at', '<=', now())
            ->update([
                'status' => SubscriptionStatus::Expired->value,
                'active' => false,
                'updated_at' => now(),
            ]);
    }

    private function currentLimitFor(Subscription $subscription): SubscriptionLimit
    {
        return $this->limitPeriods()->syncCurrentPeriod($subscription);
    }

    /**
     * @param  array<string, int>  $amounts
     */
    private function assertLimitCanRecord(SubscriptionLimit $limit, array $amounts, float $creditsUsed): void
    {
        $errors = [];

        foreach ($amounts as $metric => $amount) {
            if ($amount <= 0) {
                continue;
            }

            $limitColumn = self::METRIC_COLUMNS[$metric]['limit'];

            if ((int) $limit->{$limitColumn} < $amount) {
                $errors[$metric] = ["Insufficient {$metric} quota."];
            }
        }

        if ($creditsUsed > 0 && (float) $limit->credits_remaining < $creditsUsed) {
            $errors['credits'] = ['Insufficient credits quota.'];
        }

        if ($errors !== []) {
            $this->notifyLimitReached($limit, $errors);

            throw new SubscriptionEntitlementException('Subscription limit exceeded.', $errors);
        }
    }

    /**
     * @return array<string, int>
     */
    private function normalizeAmounts(array $amounts): array
    {
        $normalized = [];

        foreach (array_keys(self::METRIC_COLUMNS) as $metric) {
            $normalized[$metric] = max(0, (int) ($amounts[$metric] ?? 0));
        }

        return $normalized;
    }

    /**
     * @param  array<string, int>  $amounts
     * @return array<string, int|float>
     */
    private function usageColumns(array $amounts, float $creditsUsed): array
    {
        $columns = [];

        foreach ($amounts as $metric => $amount) {
            $columns[self::METRIC_COLUMNS[$metric]['use']] = $amount;
        }

        $columns['credits_used'] = $creditsUsed;

        return $columns;
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

        return round($creditsUsed, 2);
    }

    private function useBelongsToLimitPeriod(SubscriptionUse $use, SubscriptionLimit $limit): bool
    {
        if (! $use->used_at || ! $limit->period_started_at || ! $limit->period_renews_at) {
            return false;
        }

        $usedAt = Carbon::parse($use->used_at);

        return $usedAt->greaterThanOrEqualTo($limit->period_started_at)
            && $usedAt->lessThan($limit->period_renews_at);
    }

    private function includedLimitFor(Subscription $subscription, string $metric): int
    {
        return max(0, (int) config("subscriptions.plans.{$subscription->plan->value}.limits.{$metric}", 0));
    }

    private function includedCreditsFor(Subscription $subscription): float
    {
        return round(max(0, (float) config("subscriptions.plans.{$subscription->plan->value}.credits.total", 0)), 2);
    }

    private function limitPeriods(): SubscriptionLimitPeriodService
    {
        return $this->limitPeriods ?? app(SubscriptionLimitPeriodService::class);
    }

    private function notifyUsageUpdated(SubscriptionUse $use): void
    {
        $use->loadMissing('user');

        if (! $use->user instanceof User) {
            return;
        }

        app(NotificationDispatcher::class)->sendInApp($use->user, 'plan_usage_updated', [
            'usage_type' => $use->usage_type->value,
            'subscription_id' => $use->subscription_id,
            'profile_id' => $use->profile_id,
        ]);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    private function notifyLimitReached(SubscriptionLimit $limit, array $errors): void
    {
        $limit->loadMissing('subscription.user');
        $subscription = $limit->subscription;

        if (! $subscription instanceof Subscription || ! $subscription->user instanceof User) {
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
            'chat_messages' => 'message_or_chat_limit_reached',
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
