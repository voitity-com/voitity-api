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
use Illuminate\Support\Facades\Log;

class SubscriptionUsageRecorder
{
    private const CREDIT_PRECISION = 6;

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
        'incoming_audio_messages' => [
            'limit' => 'incoming_audio_messages_remaining',
            'use' => 'incoming_audio_messages_used',
        ],
        'incoming_audio_seconds' => [
            'limit' => 'incoming_audio_seconds_remaining',
            'use' => 'incoming_audio_seconds_used',
        ],
    ];

    public function __construct(
        private readonly ?SubscriptionLimitPeriodService $limitPeriods = null,
        private readonly ?SubscriptionProfileAccessService $profileAccess = null,
    ) {}

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
        $this->reserve(
            userId: $userId,
            usageType: $usageType,
            amounts: $amounts,
            idempotencyKey: $idempotencyKey,
            profileId: $profileId,
            sourceType: $sourceType,
            sourceId: $sourceId,
            metadata: $metadata,
        );

        return $this->finalize($idempotencyKey);
    }

    /**
     * Atomically reserves quota before a paid provider operation starts.
     *
     * @param  array<string, int>  $amounts
     * @param  array<string, mixed>  $metadata
     */
    public function reserve(
        int $userId,
        SubscriptionUsageType $usageType,
        array $amounts,
        string $idempotencyKey,
        ?int $profileId = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        array $metadata = []
    ): SubscriptionUse {
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
            ): SubscriptionUse {
                /** @var SubscriptionUse|null $existingUse */
                $existingUse = SubscriptionUse::where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingUse && $existingUse->status !== SubscriptionUse::STATUS_RELEASED) {
                    $normalizedAmounts = $this->normalizeAmounts($amounts);

                    if (! $this->matchesExistingUsage(
                        $existingUse,
                        $userId,
                        $usageType,
                        $normalizedAmounts,
                        $profileId
                    )) {
                        Log::warning('Subscription usage idempotency conflict rejected.', [
                            'existing_subscription_use_id' => $existingUse->id,
                            'idempotency_key' => $idempotencyKey,
                            'profile_id' => $profileId,
                            'usage_type' => $usageType->value,
                            'user_id' => $userId,
                        ]);

                        throw new \RuntimeException(
                            'Idempotency key was already used for different subscription usage.'
                        );
                    }

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

                $attributes = array_merge([
                    'subscription_id' => $subscription->id,
                    'user_id' => $user->id,
                    'profile_id' => $profileId,
                    'usage_type' => $usageType,
                    'source_type' => $sourceType,
                    'source_id' => $sourceId,
                    'idempotency_key' => $idempotencyKey,
                    'status' => SubscriptionUse::STATUS_RESERVED,
                    'metadata' => $metadata,
                    'used_at' => now(),
                    'reserved_at' => now(),
                    'finalized_at' => null,
                    'released_at' => null,
                ], $this->usageColumns($normalizedAmounts, $creditsUsed));

                if ($existingUse) {
                    $existingUse->fill($attributes);
                    $existingUse->save();
                    $use = $existingUse;
                } else {
                    $use = SubscriptionUse::create($attributes);
                }

                if (! $unlimited) {
                    foreach ($normalizedAmounts as $metric => $amount) {
                        $limitColumn = self::METRIC_COLUMNS[$metric]['limit'];
                        $limit->{$limitColumn} = max(0, ((int) $limit->{$limitColumn}) - $amount);
                    }

                    $limit->credits_remaining = round(
                        max(0, ((float) $limit->credits_remaining) - $creditsUsed),
                        self::CREDIT_PRECISION
                    );
                    $limit->save();
                }

                return $use;
            });

            Log::info('Subscription usage reserved.', [
                'idempotency_key' => $idempotencyKey,
                'profile_id' => $profileId,
                'usage_type' => $usageType->value,
                'user_id' => $userId,
            ]);

            return $use;
        } catch (SubscriptionEntitlementException $exception) {
            if ($exception->getMessage() === 'Active subscription has expired.') {
                $this->expireDueSubscriptionsFor((int) $userId);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function finalize(
        string $idempotencyKey,
        array $metadata = [],
        ?string $sourceType = null,
        ?string $sourceId = null,
    ): SubscriptionUse {
        $transitioned = false;
        $use = DB::transaction(function () use (
            $idempotencyKey,
            $metadata,
            $sourceType,
            $sourceId,
            &$transitioned
        ): SubscriptionUse {
            /** @var SubscriptionUse $use */
            $use = SubscriptionUse::where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->firstOrFail();

            if ($use->status === SubscriptionUse::STATUS_RELEASED) {
                throw new \RuntimeException('Released subscription usage cannot be finalized.');
            }

            if ($use->status === SubscriptionUse::STATUS_FINALIZED) {
                return $use;
            }

            $use->status = SubscriptionUse::STATUS_FINALIZED;
            $use->finalized_at = now();
            $use->metadata = array_replace($use->metadata ?? [], $metadata);
            $use->source_type = $sourceType ?? $use->source_type;
            $use->source_id = $sourceId ?? $use->source_id;
            $use->save();
            $transitioned = true;

            return $use;
        });

        if ($transitioned) {
            $this->notifyUsageUpdated($use);
            Log::info('Subscription usage finalized.', [
                'idempotency_key' => $idempotencyKey,
                'subscription_use_id' => $use->id,
                'usage_type' => $use->usage_type->value,
            ]);
        }

        return $use;
    }

    public function release(string $idempotencyKey): bool
    {
        $released = DB::transaction(function () use ($idempotencyKey): bool {
            /** @var SubscriptionUse|null $use */
            $use = SubscriptionUse::where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if (! $use || $use->status === SubscriptionUse::STATUS_RELEASED) {
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
                    round(
                        ((float) $limit->credits_remaining) + ((float) $use->credits_used),
                        self::CREDIT_PRECISION
                    )
                );
                $limit->save();
            }

            $use->status = SubscriptionUse::STATUS_RELEASED;
            $use->released_at = now();
            $use->save();

            return true;
        });

        if ($released) {
            Log::info('Subscription usage released.', [
                'idempotency_key' => $idempotencyKey,
            ]);
        }

        return $released;
    }

    public function releaseStaleReservations(?Carbon $now = null): int
    {
        $cutoff = ($now ?? now())->copy()->subMinutes(
            max(1, (int) config('subscriptions.usage_reservation_ttl_minutes', 60))
        );
        $released = 0;

        SubscriptionUse::query()
            ->where('status', SubscriptionUse::STATUS_RESERVED)
            ->where('reserved_at', '<=', $cutoff)
            ->orderBy('id')
            ->select(['id', 'idempotency_key'])
            ->chunkById(100, function ($uses) use (&$released): void {
                foreach ($uses as $use) {
                    if ($this->release((string) $use->idempotency_key)) {
                        $released++;
                    }
                }
            });

        if ($released > 0) {
            Log::warning('Stale subscription usage reservations released.', [
                'cutoff' => $cutoff->toIso8601String(),
                'released_count' => $released,
            ]);
        } else {
            Log::info('Stale subscription usage reservation sweep completed.', [
                'cutoff' => $cutoff->toIso8601String(),
                'released_count' => 0,
            ]);
        }

        return $released;
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
        $subscriptions = Subscription::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->where('renews_at', '<=', now())
            ->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        Subscription::query()
            ->whereKey($subscriptions->modelKeys())
            ->update([
                'status' => SubscriptionStatus::Expired->value,
                'active' => false,
                'updated_at' => now(),
            ]);

        $deactivatedProfiles = $this->profileAccess()
            ->deactivateProfilesIfAccessEnded(
                $userId,
                'subscription_expired_during_usage_recording',
                $subscriptions->first()?->id
            );

        foreach ($subscriptions as $subscription) {
            if ($subscription instanceof Subscription) {
                $subscription->active = false;
                $subscription->status = SubscriptionStatus::Expired;
                $this->notifySubscriptionDeactivated($subscription);
            }
        }

        Log::warning('Expired subscriptions persisted after usage was rejected.', [
            'deactivated_profile_count' => $deactivatedProfiles,
            'subscription_ids' => $subscriptions->modelKeys(),
            'user_id' => $userId,
        ]);
    }

    private function currentLimitFor(Subscription $subscription): SubscriptionLimit
    {
        return $this->limitPeriods()->syncCurrentPeriod($subscription);
    }

    private function profileAccess(): SubscriptionProfileAccessService
    {
        return $this->profileAccess ?? app(SubscriptionProfileAccessService::class);
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

        return round($creditsUsed, self::CREDIT_PRECISION);
    }

    /**
     * @param  array<string, int>  $amounts
     */
    private function matchesExistingUsage(
        SubscriptionUse $use,
        int $userId,
        SubscriptionUsageType $usageType,
        array $amounts,
        ?int $profileId
    ): bool {
        if (
            (int) $use->user_id !== $userId
            || $use->usage_type !== $usageType
            || ($use->profile_id === null ? null : (int) $use->profile_id) !== $profileId
        ) {
            return false;
        }

        foreach ($amounts as $metric => $amount) {
            if ((int) $use->{self::METRIC_COLUMNS[$metric]['use']} !== $amount) {
                return false;
            }
        }

        return true;
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
        if ($subscription->status === SubscriptionStatus::Trialing) {
            return max(0, (int) config("subscriptions.trial.limits.{$metric}", 0));
        }

        return max(0, (int) config("subscriptions.plans.{$subscription->plan->value}.limits.{$metric}", 0));
    }

    private function includedCreditsFor(Subscription $subscription): float
    {
        if ($subscription->status === SubscriptionStatus::Trialing) {
            return round(
                max(0, (float) config('subscriptions.trial.credits.total', 0)),
                self::CREDIT_PRECISION
            );
        }

        return round(
            max(0, (float) config("subscriptions.plans.{$subscription->plan->value}.credits.total", 0)),
            self::CREDIT_PRECISION
        );
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
