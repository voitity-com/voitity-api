<?php

namespace App\Classes\Subscriptions;

use App\Enums\SubscriptionUsageType;
use App\Models\Message;
use App\Models\ProfileAvatar;
use App\Models\SubscriptionLimit;
use App\Models\SubscriptionUse;
use Illuminate\Support\Facades\Log;

class SubscriptionUsageAccountingRepairService
{
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

    public function __construct(
        private readonly SubscriptionUsageRecorder $recorder,
        private readonly AvatarGenerationUsageService $avatarUsage,
    ) {}

    /** @return array{audio_uses_repaired: int, avatar_generations_repaired: int, limits_rebuilt: int} */
    public function repair(?int $userId = null): array
    {
        $audioRepairs = $this->repairIncomingAudioSeconds($userId);
        $avatarRepairs = $this->repairLegacyAvatarFunding($userId);
        $limitsRebuilt = $this->rebuildCurrentLimits($userId);

        $summary = [
            'audio_uses_repaired' => $audioRepairs,
            'avatar_generations_repaired' => $avatarRepairs,
            'limits_rebuilt' => $limitsRebuilt,
        ];

        Log::notice('Subscription usage accounting repair completed.', [
            ...$summary,
            'user_id' => $userId,
        ]);

        return $summary;
    }

    private function repairIncomingAudioSeconds(?int $userId): int
    {
        $repaired = 0;

        SubscriptionUse::query()
            ->where('usage_type', SubscriptionUsageType::IncomingAudioMessage)
            ->where('status', SubscriptionUse::STATUS_FINALIZED)
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->orderBy('id')
            ->get()
            ->each(function (SubscriptionUse $use) use (&$repaired): void {
                $seconds = $this->authoritativeAudioSeconds($use);

                if ($seconds === null || $seconds === (int) $use->incoming_audio_seconds_used) {
                    return;
                }

                $originalUsedAt = $use->used_at?->copy();
                $originalFinalizedAt = $use->finalized_at?->copy();
                $metadata = array_replace($use->metadata ?? [], [
                    'accounting_repaired_at' => now()->toJSON(),
                    'duration_seconds' => $seconds,
                    'duration_source' => 'stored_transcription',
                    'previous_duration_seconds' => (int) $use->incoming_audio_seconds_used,
                ]);

                $this->recorder->release((string) $use->idempotency_key);
                $this->recorder->record(
                    userId: (int) $use->user_id,
                    usageType: SubscriptionUsageType::IncomingAudioMessage,
                    amounts: [
                        'chat_messages' => max(0, (int) $use->chat_messages_used),
                        'incoming_audio_messages' => max(1, (int) $use->incoming_audio_messages_used),
                        'incoming_audio_seconds' => $seconds,
                    ],
                    idempotencyKey: (string) $use->idempotency_key,
                    profileId: $use->profile_id ? (int) $use->profile_id : null,
                    sourceType: $use->source_type,
                    sourceId: $use->source_id,
                    metadata: $metadata,
                );

                SubscriptionUse::whereKey($use->id)->update([
                    'used_at' => $originalUsedAt,
                    'finalized_at' => $originalFinalizedAt,
                ]);
                $repaired++;
            });

        return $repaired;
    }

    private function repairLegacyAvatarFunding(?int $userId): int
    {
        $repaired = 0;

        ProfileAvatar::query()
            ->with(['user', 'profile'])
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->orderBy('id')
            ->get()
            ->each(function (ProfileAvatar $avatar) use (&$repaired): void {
                if (! $avatar->user || ! $avatar->profile || $this->avatarUsage->exists($avatar)) {
                    return;
                }

                $imageUse = SubscriptionUse::where(
                    'idempotency_key',
                    "avatar-image:profile-avatar:{$avatar->id}"
                )->where('status', SubscriptionUse::STATUS_FINALIZED)->first();
                $videoUse = SubscriptionUse::where(
                    'idempotency_key',
                    "avatar-video:profile-avatar:{$avatar->id}"
                )->where('status', SubscriptionUse::STATUS_FINALIZED)->first();

                if (
                    ! $imageUse
                    || ! $videoUse
                    || (int) (($imageUse->credit_covered ?? [])['avatar_images'] ?? 0) < 1
                    || (int) (($videoUse->plan_covered ?? [])['avatar_video_seconds'] ?? 0) < 1
                ) {
                    return;
                }

                $usedAt = $imageUse->used_at->min($videoUse->used_at);
                $this->recorder->release((string) $imageUse->idempotency_key);
                $this->recorder->release((string) $videoUse->idempotency_key);
                $use = $this->avatarUsage->reserve($avatar->user, $avatar->profile, $avatar);
                $this->avatarUsage->finalize($avatar, [
                    'accounting_repaired_at' => now()->toJSON(),
                    'repaired_from_legacy_use_ids' => [$imageUse->id, $videoUse->id],
                ]);
                $use->used_at = $usedAt;
                $use->save();
                $repaired++;
            });

        return $repaired;
    }

    private function rebuildCurrentLimits(?int $userId): int
    {
        $rebuilt = 0;

        SubscriptionLimit::query()
            ->with('usagePeriod')
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->orderBy('id')
            ->get()
            ->each(function (SubscriptionLimit $limit) use (&$rebuilt): void {
                $snapshot = $limit->usagePeriod?->limits_snapshot;

                if (! is_array($snapshot)) {
                    return;
                }

                $uses = SubscriptionUse::query()
                    ->where('usage_period_id', $limit->usage_period_id)
                    ->where('status', '!=', SubscriptionUse::STATUS_RELEASED)
                    ->get();

                foreach (self::LIMIT_COLUMNS as $metric => $column) {
                    $planUsed = $uses->sum(
                        fn (SubscriptionUse $use): int => (int) (($use->plan_covered ?? [])[$metric] ?? 0)
                    );
                    $limit->{$column} = max(0, (int) ($snapshot[$metric] ?? 0) - $planUsed);
                }

                $limit->save();
                $rebuilt++;
            });

        return $rebuilt;
    }

    private function authoritativeAudioSeconds(SubscriptionUse $use): ?int
    {
        $metadataDuration = ($use->metadata ?? [])['transcription_duration_seconds'] ?? null;
        $duration = is_numeric($metadataDuration) ? (float) $metadataDuration : null;
        $messageId = $use->source_type === Message::class ? $use->source_id : null;
        $messageId ??= ($use->metadata ?? [])['message_id'] ?? null;

        if ($duration === null && $messageId) {
            $message = Message::find($messageId);
            $storedDuration = $message?->data['request']['transcription']['duration'] ?? null;
            $duration = is_numeric($storedDuration) ? (float) $storedDuration : null;
        }

        if ($duration === null || ! is_finite($duration) || $duration <= 0) {
            return null;
        }

        $max = max(1, (int) config('subscriptions.audio_message_max_duration_seconds', 30));
        $tolerance = max(0.0, (float) config('subscriptions.audio_message_duration_tolerance_seconds', 0.5));
        $rounded = (int) ceil($duration);

        return $duration <= $max + $tolerance ? min($max, $rounded) : $rounded;
    }
}
