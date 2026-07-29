<?php

namespace App\Classes\ProfilePublication;

use App\Classes\Subscriptions\SubscriptionEntitlementService;
use App\Classes\Subscriptions\SubscriptionPlanCatalog;
use App\Enums\ProfileStatus;
use App\Exceptions\Subscriptions\SubscriptionEntitlementException;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProfileActivationService
{
    public function __construct(
        private readonly SubscriptionEntitlementService $entitlements,
        private readonly SubscriptionPlanCatalog $planCatalog,
    ) {}

    public function activate(User $user, Profile $profile): Profile
    {
        $subscription = $this->entitlements->assertHasActiveSubscription($user);

        return DB::transaction(function () use ($profile, $subscription, $user): Profile {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            /** @var Subscription|null $lockedSubscription */
            $lockedSubscription = Subscription::query()
                ->whereKey($subscription->id)
                ->where('active', true)
                ->where('renews_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $lockedSubscription instanceof Subscription) {
                Log::warning('Profile activation rejected because subscription access changed during the request.', [
                    'profile_id' => $profile->id,
                    'subscription_id' => $subscription->id,
                    'user_id' => $user->id,
                ]);

                throw new SubscriptionEntitlementException(
                    'Active subscription not found.',
                    ['subscription' => ['An active subscription is required to publish a profile.']]
                );
            }

            /** @var Profile $lockedProfile */
            $lockedProfile = Profile::query()
                ->whereKey($profile->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedProfile->active && $lockedProfile->status === ProfileStatus::Published) {
                return $lockedProfile;
            }

            if (! $this->planCatalog->isUnlimited($lockedSubscription->plan)) {
                $limit = max(0, (int) ($this->planCatalog->configFor($lockedSubscription->plan)['limits']['profiles'] ?? 0));
                $activeProfileCount = Profile::query()
                    ->where('user_id', $user->id)
                    ->whereKeyNot($lockedProfile->id)
                    ->where('active', true)
                    ->count();

                if ($activeProfileCount >= $limit) {
                    Log::warning('Profile activation rejected because the active profile limit was reached.', [
                        'active_profile_count' => $activeProfileCount,
                        'active_profile_limit' => $limit,
                        'plan' => $lockedSubscription->plan->value,
                        'profile_id' => $lockedProfile->id,
                        'subscription_id' => $lockedSubscription->id,
                        'user_id' => $user->id,
                    ]);

                    throw new SubscriptionEntitlementException(
                        'Active profile limit reached.',
                        ['profiles' => ['Deactivate the currently published profile before activating another one.']],
                        409
                    );
                }
            }

            $lockedProfile->forceFill([
                'active' => true,
                'status' => ProfileStatus::Published,
            ])->save();

            Log::info('Profile activated within subscription limit.', [
                'plan' => $lockedSubscription->plan->value,
                'profile_id' => $lockedProfile->id,
                'subscription_id' => $lockedSubscription->id,
                'user_id' => $user->id,
            ]);

            return $lockedProfile->fresh();
        });
    }
}
