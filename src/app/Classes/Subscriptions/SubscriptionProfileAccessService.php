<?php

namespace App\Classes\Subscriptions;

use App\Enums\ProfileStatus;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SubscriptionProfileAccessService
{
    public function deactivateProfilesIfAccessEnded(
        User|int $user,
        string $reason,
        ?int $subscriptionId = null
    ): int {
        $userId = $user instanceof User ? $user->id : $user;
        $hasCurrentSubscription = Subscription::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->where('renews_at', '>', now())
            ->exists();

        if ($hasCurrentSubscription) {
            Log::info('Profile deactivation skipped because subscription access remains active.', [
                'reason' => $reason,
                'subscription_id' => $subscriptionId,
                'user_id' => $userId,
            ]);

            return 0;
        }

        $profileIds = Profile::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->pluck('id');

        if ($profileIds->isEmpty()) {
            Log::info('Subscription access ended with no active profiles to deactivate.', [
                'reason' => $reason,
                'subscription_id' => $subscriptionId,
                'user_id' => $userId,
            ]);

            return 0;
        }

        $suspendingSubscriptionId = $subscriptionId !== null
            && Subscription::query()->whereKey($subscriptionId)->exists()
            ? $subscriptionId
            : null;

        $deactivated = DB::transaction(function () use ($profileIds, $suspendingSubscriptionId): int {
            $profiles = Profile::query()
                ->whereKey($profileIds)
                ->lockForUpdate()
                ->get();

            foreach ($profiles as $profile) {
                $previousStatus = $profile->status instanceof ProfileStatus
                    ? $profile->status->value
                    : (string) $profile->status;

                $profile->forceFill([
                    'active' => false,
                    'status' => ProfileStatus::Hidden,
                    'subscription_suspended_at' => now(),
                    'suspended_by_subscription_id' => $suspendingSubscriptionId,
                    'subscription_suspension_previous_status' => $previousStatus,
                ])->save();
            }

            return $profiles->count();
        });

        Log::warning('Profiles deactivated because subscription access ended.', [
            'profile_count' => $deactivated,
            'profile_ids' => $profileIds->values()->all(),
            'reason' => $reason,
            'subscription_id' => $subscriptionId,
            'user_id' => $userId,
        ]);

        return $deactivated;
    }

    public function restoreProfilesAfterPaymentRecovery(
        Subscription $subscription,
        int $sourceSubscriptionId,
    ): int {
        $planConfig = config("subscriptions.plans.{$subscription->plan->value}", []);
        $limit = ($planConfig['unlimited'] ?? false) === true
            ? PHP_INT_MAX
            : max(0, (int) ($planConfig['limits']['profiles'] ?? 0));

        return DB::transaction(function () use ($limit, $sourceSubscriptionId, $subscription): int {
            $profiles = Profile::query()
                ->where('user_id', $subscription->user_id)
                ->where('suspended_by_subscription_id', $sourceSubscriptionId)
                ->whereNotNull('subscription_suspended_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $restoredIds = [];

            foreach ($profiles as $index => $profile) {
                $restore = $index < $limit;
                $previousStatus = $profile->subscription_suspension_previous_status;
                $status = $restore && is_string($previousStatus)
                    && ProfileStatus::tryFrom($previousStatus) instanceof ProfileStatus
                    ? $previousStatus
                    : ProfileStatus::Hidden->value;

                $profile->forceFill([
                    'active' => $restore,
                    'status' => $status,
                    'subscription_suspended_at' => null,
                    'suspended_by_subscription_id' => null,
                    'subscription_suspension_previous_status' => null,
                ])->save();

                if ($restore) {
                    $restoredIds[] = $profile->id;
                }
            }

            Log::info('Profiles processed after subscription payment recovery.', [
                'active_profile_limit' => $limit === PHP_INT_MAX ? 'unlimited' : $limit,
                'profile_count' => $profiles->count(),
                'restored_profile_count' => count($restoredIds),
                'restored_profile_ids' => $restoredIds,
                'source_subscription_id' => $sourceSubscriptionId,
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
            ]);

            return count($restoredIds);
        });
    }

    public function enforceActiveProfileLimit(Subscription $subscription): int
    {
        $planConfig = config("subscriptions.plans.{$subscription->plan->value}", []);

        if (($planConfig['unlimited'] ?? false) === true) {
            return 0;
        }

        $limit = max(0, (int) ($planConfig['limits']['profiles'] ?? 0));
        $activeProfiles = Profile::query()
            ->where('user_id', $subscription->user_id)
            ->where('active', true)
            ->lockForUpdate()
            ->get()
            ->sort(function (Profile $left, Profile $right): int {
                $leftPublished = $left->status === ProfileStatus::Published ? 1 : 0;
                $rightPublished = $right->status === ProfileStatus::Published ? 1 : 0;

                if ($leftPublished !== $rightPublished) {
                    return $rightPublished <=> $leftPublished;
                }

                return $right->updated_at?->getTimestamp() <=> $left->updated_at?->getTimestamp();
            })
            ->values();

        if ($activeProfiles->count() <= $limit) {
            return 0;
        }

        $profilesToSuspend = $activeProfiles->slice($limit);

        foreach ($profilesToSuspend as $profile) {
            $previousStatus = $profile->status instanceof ProfileStatus
                ? $profile->status->value
                : (string) $profile->status;

            $profile->forceFill([
                'active' => false,
                'status' => ProfileStatus::Hidden->value,
                'subscription_suspended_at' => now(),
                'suspended_by_subscription_id' => $subscription->id,
                'subscription_suspension_previous_status' => $previousStatus,
            ])->save();
        }

        $deactivated = $profilesToSuspend->count();

        Log::warning('Active profiles exceeded a newly activated subscription limit and were deactivated for reselection.', [
            'active_profile_limit' => $limit,
            'plan' => $subscription->plan->value,
            'profile_count' => $deactivated,
            'profile_ids' => $profilesToSuspend->pluck('id')->values()->all(),
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
        ]);

        return $deactivated;
    }

    public function restoreProfilesAfterPlanUpgrade(
        Subscription $subscription,
        int $sourceSubscriptionId,
    ): int {
        $planConfig = config("subscriptions.plans.{$subscription->plan->value}", []);
        $unlimited = ($planConfig['unlimited'] ?? false) === true;
        $limit = $unlimited ? PHP_INT_MAX : max(0, (int) ($planConfig['limits']['profiles'] ?? 0));

        return DB::transaction(function () use ($limit, $sourceSubscriptionId, $subscription): int {
            $activeCount = Profile::query()
                ->where('user_id', $subscription->user_id)
                ->where('active', true)
                ->count();
            $available = $limit === PHP_INT_MAX ? PHP_INT_MAX : max(0, $limit - $activeCount);

            if ($available === 0) {
                return 0;
            }

            $profiles = Profile::query()
                ->where('user_id', $subscription->user_id)
                ->where('suspended_by_subscription_id', $sourceSubscriptionId)
                ->whereNotNull('subscription_suspended_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $restored = 0;

            foreach ($profiles as $profile) {
                if ($restored >= $available) {
                    break;
                }

                $previousStatus = $profile->subscription_suspension_previous_status;
                $status = is_string($previousStatus) && ProfileStatus::tryFrom($previousStatus)
                    ? $previousStatus
                    : ProfileStatus::Draft->value;

                $profile->forceFill([
                    'active' => true,
                    'status' => $status,
                    'subscription_suspended_at' => null,
                    'suspended_by_subscription_id' => null,
                    'subscription_suspension_previous_status' => null,
                ])->save();
                $restored++;
            }

            return $restored;
        });
    }
}
