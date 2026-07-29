<?php

namespace App\Classes\Subscriptions;

use App\Enums\ProfileStatus;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
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

        $deactivated = Profile::query()
            ->whereKey($profileIds)
            ->update([
                'active' => false,
                'status' => ProfileStatus::Hidden->value,
                'updated_at' => now(),
            ]);

        Log::warning('Profiles deactivated because subscription access ended.', [
            'profile_count' => $deactivated,
            'profile_ids' => $profileIds->values()->all(),
            'reason' => $reason,
            'subscription_id' => $subscriptionId,
            'user_id' => $userId,
        ]);

        return $deactivated;
    }

    public function enforceActiveProfileLimit(Subscription $subscription): int
    {
        $planConfig = config("subscriptions.plans.{$subscription->plan->value}", []);

        if (($planConfig['unlimited'] ?? false) === true) {
            return 0;
        }

        $limit = max(0, (int) ($planConfig['limits']['profiles'] ?? 0));
        $activeProfileIds = Profile::query()
            ->where('user_id', $subscription->user_id)
            ->where('active', true)
            ->pluck('id');

        if ($activeProfileIds->count() <= $limit) {
            return 0;
        }

        $deactivated = Profile::query()
            ->whereKey($activeProfileIds)
            ->update([
                'active' => false,
                'status' => ProfileStatus::Hidden->value,
                'updated_at' => now(),
            ]);

        Log::warning('Active profiles exceeded a newly activated subscription limit and were deactivated for reselection.', [
            'active_profile_limit' => $limit,
            'plan' => $subscription->plan->value,
            'profile_count' => $deactivated,
            'profile_ids' => $activeProfileIds->values()->all(),
            'subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
        ]);

        return $deactivated;
    }
}
