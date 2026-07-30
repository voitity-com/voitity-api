<?php

namespace App\Classes\Subscriptions;

use App\Enums\ProfileStatus;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use Illuminate\Support\Facades\Log;

class ProfileMessagingCapabilitiesService
{
    public function __construct(
        private readonly SubscriptionLimitPeriodService $limitPeriods,
        private readonly SubscriptionPlanCatalog $plans,
    ) {}

    /**
     * @return array{
     *     text_messages_enabled: bool,
     *     audio_messages_enabled: bool,
     *     audio_max_duration_seconds: int,
     *     reason: string|null
     * }
     */
    public function forProfile(Profile $profile): array
    {
        $disabled = $this->disabledCapabilities('profile_inactive');

        if (! $profile->active || $profile->status !== ProfileStatus::Published || ! $profile->user_id) {
            return $disabled;
        }

        /** @var Subscription|null $subscription */
        $subscription = Subscription::query()
            ->where('user_id', $profile->user_id)
            ->where('active', true)
            ->where('renews_at', '>', now())
            ->latest('started_at')
            ->first();

        if (! $subscription) {
            return $this->disabledCapabilities('subscription_inactive');
        }

        if ($this->plans->isUnlimited($subscription->plan)) {
            return $this->enabledCapabilities();
        }

        try {
            $limit = $this->limitPeriods->syncCurrentPeriod($subscription);
        } catch (\Throwable $exception) {
            Log::warning('Unable to resolve public profile messaging capabilities.', [
                'error' => $exception->getMessage(),
                'profile_id' => $profile->id,
                'subscription_id' => $subscription->id,
            ]);

            return $this->disabledCapabilities('limits_unavailable');
        }

        if (! $limit instanceof SubscriptionLimit || (int) $limit->chat_messages_remaining <= 0) {
            return $this->disabledCapabilities('chat_message_limit_reached');
        }

        $audioEnabled = (int) $limit->incoming_audio_messages_remaining > 0
            && (int) $limit->incoming_audio_seconds_remaining > 0;

        return [
            'text_messages_enabled' => true,
            'audio_messages_enabled' => $audioEnabled,
            'audio_max_duration_seconds' => max(
                1,
                (int) config('subscriptions.audio_message_max_duration_seconds', 30)
            ),
            'reason' => $audioEnabled ? null : 'audio_message_limit_reached',
        ];
    }

    private function enabledCapabilities(): array
    {
        return [
            'text_messages_enabled' => true,
            'audio_messages_enabled' => true,
            'audio_max_duration_seconds' => max(
                1,
                (int) config('subscriptions.audio_message_max_duration_seconds', 30)
            ),
            'reason' => null,
        ];
    }

    private function disabledCapabilities(string $reason): array
    {
        return [
            'text_messages_enabled' => false,
            'audio_messages_enabled' => false,
            'audio_max_duration_seconds' => max(
                1,
                (int) config('subscriptions.audio_message_max_duration_seconds', 30)
            ),
            'reason' => $reason,
        ];
    }
}
