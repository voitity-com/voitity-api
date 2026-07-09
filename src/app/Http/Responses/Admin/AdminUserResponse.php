<?php

namespace App\Http\Responses\Admin;

use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;

class AdminUserResponse
{
    public function __construct(
        private readonly User $user,
        private readonly bool $includeProfiles = false
    ) {}

    public function toArray(): array
    {
        $data = [
            'id' => $this->user->id,
            'name' => $this->user->name,
            'first_name' => $this->user->first_name,
            'last_name' => $this->user->last_name,
            'email' => $this->user->email,
            'role' => $this->user->role,
            'avatar' => $this->user->avatar,
            'provider' => $this->user->provider,
            'counts' => [
                'profiles' => (int) ($this->user->profiles_count ?? 0),
                'sources' => (int) ($this->user->profile_sources_count ?? 0),
                'avatars' => (int) ($this->user->profile_avatars_count ?? 0),
                'voices' => (int) ($this->user->voices_count ?? 0),
                'ai_images' => (int) ($this->user->ai_images_count ?? 0),
                'ai_videos' => (int) ($this->user->ai_videos_count ?? 0),
                'chats' => (int) ($this->user->profile_chats_count ?? 0),
            ],
            'subscription' => $this->subscriptionData(),
            'created_at' => $this->user->created_at?->toJSON(),
            'updated_at' => $this->user->updated_at?->toJSON(),
        ];

        if ($this->includeProfiles && $this->user->relationLoaded('profiles')) {
            $data['profiles'] = $this->user->profiles
                ->map(fn (Profile $profile) => [
                    'id' => $profile->id,
                    'alias' => $profile->alias,
                    'name' => $profile->name,
                    'active' => (bool) $profile->active,
                    'status' => $profile->status?->value,
                    'counts' => [
                        'sources' => (int) ($profile->sources_count ?? 0),
                        'avatars' => (int) ($profile->avatars_count ?? 0),
                        'voices' => (int) ($profile->voices_count ?? 0),
                        'chats' => (int) ($profile->chats_count ?? 0),
                        'ai_images' => (int) ($profile->ai_images_count ?? 0),
                        'ai_videos' => (int) ($profile->ai_videos_count ?? 0),
                    ],
                    'created_at' => $profile->created_at?->toJSON(),
                    'updated_at' => $profile->updated_at?->toJSON(),
                ])
                ->values()
                ->all();
        }

        return $data;
    }

    private function subscriptionData(): ?array
    {
        $subscription = $this->user->relationLoaded('activeSubscription')
            ? $this->user->activeSubscription
            : $this->user->activeSubscription()->first();

        if (! $subscription instanceof Subscription) {
            return null;
        }

        $plan = $subscription->plan->value;
        $planConfig = config("subscriptions.plans.{$plan}", []);

        return [
            'id' => $subscription->id,
            'plan' => $plan,
            'plan_name' => $planConfig['name'] ?? $plan,
            'status' => $subscription->status->value,
            'active' => (bool) $subscription->active,
            'unlimited' => (bool) ($planConfig['unlimited'] ?? false),
            'billing_mode' => $subscription->billing_mode,
            'cancel_at_period_end' => (bool) $subscription->cancel_at_period_end,
            'started_at' => $subscription->started_at?->toJSON(),
            'renews_at' => $subscription->renews_at?->toJSON(),
            'next_billing_at' => $subscription->next_billing_at?->toJSON(),
        ];
    }
}
