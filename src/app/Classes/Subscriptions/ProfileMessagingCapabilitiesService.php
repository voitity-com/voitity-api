<?php

namespace App\Classes\Subscriptions;

use App\Enums\ProfileStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
use Illuminate\Support\Facades\Log;

class ProfileMessagingCapabilitiesService
{
    public function __construct(
        private readonly SubscriptionLimitPeriodService $limitPeriods,
        private readonly SubscriptionPlanCatalog $plans,
        private readonly CreditWalletService $wallets,
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

        if (! $limit instanceof SubscriptionLimit) {
            return $this->disabledCapabilities('limits_unavailable');
        }

        $wallet = $this->wallets->walletForUser((int) $subscription->user_id);
        $availableUnits = $wallet->debt_units > 0 || $subscription->status === SubscriptionStatus::Trialing
            ? 0
            : (int) $wallet->available_units;
        $rates = config('subscriptions.credit_store.rates_in_units', []);
        $chatCreditUnits = (int) $limit->chat_messages_remaining > 0
            ? 0
            : max(0, (int) ($rates['chat_messages'] ?? 0));
        $textEnabled = (int) $limit->chat_messages_remaining > 0
            || ($chatCreditUnits > 0 && $availableUnits >= $chatCreditUnits);

        if (! $textEnabled) {
            return $this->disabledCapabilities('chat_message_limit_reached');
        }

        $configuredMaxDuration = max(
            1,
            (int) config('subscriptions.audio_message_max_duration_seconds', 30)
        );
        $planAudioAvailable = (int) $limit->incoming_audio_messages_remaining > 0
            && (int) $limit->incoming_audio_seconds_remaining > 0;
        $audioMaxDuration = $planAudioAvailable
            ? min($configuredMaxDuration, (int) $limit->incoming_audio_seconds_remaining)
            : $this->affordableAudioSeconds($availableUnits - $chatCreditUnits, $configuredMaxDuration, $rates);
        $audioEnabled = $audioMaxDuration > 0;

        return [
            'text_messages_enabled' => $textEnabled,
            'audio_messages_enabled' => $audioEnabled,
            'audio_max_duration_seconds' => $audioEnabled ? $audioMaxDuration : $configuredMaxDuration,
            'reason' => $audioEnabled ? null : 'audio_message_limit_reached',
        ];
    }

    /**
     * @param  array<string, mixed>  $rates
     */
    private function affordableAudioSeconds(int $availableUnits, int $configuredMaxDuration, array $rates): int
    {
        $ratePerSecond = max(0, (int) ($rates['incoming_audio_seconds'] ?? 0));

        if ($availableUnits <= 0 || $ratePerSecond <= 0) {
            return 0;
        }

        return min($configuredMaxDuration, intdiv($availableUnits, $ratePerSecond));
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
