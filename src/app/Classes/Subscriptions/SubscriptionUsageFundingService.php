<?php

namespace App\Classes\Subscriptions;

use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionUsageType;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;

class SubscriptionUsageFundingService
{
    /**
     * @var array<string, string>
     */
    private const LIMIT_COLUMNS = [
        'profiles' => 'profiles_remaining',
        'avatar_images' => 'avatar_images_remaining',
        'avatar_video_seconds' => 'avatar_video_seconds_remaining',
        'voice_clones' => 'voice_clones_remaining',
        'tts_characters' => 'tts_characters_remaining',
        'chat_messages' => 'chat_messages_remaining',
        'incoming_audio_messages' => 'incoming_audio_messages_remaining',
        'incoming_audio_seconds' => 'incoming_audio_seconds_remaining',
    ];

    /**
     * @param  array<string, int>  $amounts
     */
    public function allocate(
        Subscription $subscription,
        SubscriptionLimit $limit,
        SubscriptionUsageType $usageType,
        array $amounts,
    ): SubscriptionUsageAllocation {
        if ((bool) config("subscriptions.plans.{$subscription->plan->value}.unlimited", false)) {
            return new SubscriptionUsageAllocation($amounts, [], 0);
        }

        $planCovered = [];
        $creditCovered = [];

        foreach ($amounts as $metric => $amount) {
            if ($amount <= 0 || ! isset(self::LIMIT_COLUMNS[$metric])) {
                continue;
            }

            $remaining = max(0, (int) $limit->{self::LIMIT_COLUMNS[$metric]});
            $included = min($amount, $remaining);

            if ($included > 0) {
                $planCovered[$metric] = $included;
            }

            if ($included < $amount) {
                $creditCovered[$metric] = $amount - $included;
            }
        }

        $this->applyIncomingAudioBundlePolicy($usageType, $amounts, $limit, $planCovered, $creditCovered);

        $creditUnits = 0;
        $errors = [];
        $rates = config('subscriptions.credit_store.rates_in_units', []);

        foreach ($creditCovered as $metric => $amount) {
            if ($subscription->status === SubscriptionStatus::Trialing) {
                $errors[$metric] = ["Insufficient {$metric} trial quota; trials cannot use purchased credits."];

                continue;
            }

            $rate = isset($rates[$metric]) ? max(0, (int) $rates[$metric]) : null;

            if ($rate === null) {
                $errors[$metric] = ["Insufficient {$metric} quota; this limit cannot be extended with credits."];

                continue;
            }

            $creditUnits += $amount * $rate;
        }

        return new SubscriptionUsageAllocation(
            planCovered: $planCovered,
            creditCovered: $creditCovered,
            creditUnits: $creditUnits,
            errors: $errors,
        );
    }

    /**
     * Audio count and duration are one provider operation. If either included
     * limit cannot fund the request, credits fund the complete transcription.
     *
     * @param  array<string, int>  $amounts
     * @param  array<string, int>  $planCovered
     * @param  array<string, int>  $creditCovered
     */
    private function applyIncomingAudioBundlePolicy(
        SubscriptionUsageType $usageType,
        array $amounts,
        SubscriptionLimit $limit,
        array &$planCovered,
        array &$creditCovered,
    ): void {
        if ($usageType !== SubscriptionUsageType::IncomingAudioMessage) {
            return;
        }

        $messageAmount = max(0, (int) ($amounts['incoming_audio_messages'] ?? 0));
        $secondsAmount = max(0, (int) ($amounts['incoming_audio_seconds'] ?? 0));

        if (
            (int) $limit->incoming_audio_messages_remaining >= $messageAmount
            && (int) $limit->incoming_audio_seconds_remaining >= $secondsAmount
        ) {
            return;
        }

        unset($planCovered['incoming_audio_messages'], $planCovered['incoming_audio_seconds']);

        if ($messageAmount > 0) {
            $creditCovered['incoming_audio_messages'] = $messageAmount;
        }

        if ($secondsAmount > 0) {
            $creditCovered['incoming_audio_seconds'] = $secondsAmount;
        }
    }
}
