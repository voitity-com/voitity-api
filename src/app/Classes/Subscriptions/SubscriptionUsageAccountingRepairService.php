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
        $claimedMessageIds = $this->claimedAudioMessageIds($userId);

        SubscriptionUse::query()
            ->where('usage_type', SubscriptionUsageType::IncomingAudioMessage)
            ->where('status', SubscriptionUse::STATUS_FINALIZED)
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->orderBy('id')
            ->get()
            ->each(function (SubscriptionUse $use) use (&$claimedMessageIds, &$repaired): void {
                $message = $this->audioMessageForUse($use, $claimedMessageIds);
                $seconds = $this->authoritativeAudioSeconds($use, $message);
                $needsMessageLink = $message
                    && ($use->source_type !== Message::class || (int) $use->source_id !== (int) $message->id);

                if ($message) {
                    $claimedMessageIds[(int) $message->id] = true;
                }

                if (
                    $seconds === null
                    || ($seconds === (int) $use->incoming_audio_seconds_used && ! $needsMessageLink)
                ) {
                    return;
                }

                $originalUsedAt = $use->used_at?->copy();
                $originalFinalizedAt = $use->finalized_at?->copy();
                $transcriptionDuration = $message ? $this->messageTranscriptionDuration($message) : null;
                $metadata = array_replace($use->metadata ?? [], [
                    'accounting_repaired_at' => now()->toJSON(),
                    'duration_seconds' => $seconds,
                    'duration_source' => 'stored_transcription',
                    'message_id' => $message?->id ?? ($use->metadata ?? [])['message_id'] ?? null,
                    'previous_duration_seconds' => (int) $use->incoming_audio_seconds_used,
                    'transcription_duration_seconds' => $transcriptionDuration
                        ?? ($use->metadata ?? [])['transcription_duration_seconds']
                        ?? null,
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
                    sourceType: $message ? Message::class : $use->source_type,
                    sourceId: $message ? (string) $message->id : $use->source_id,
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

    /** @param array<int, true> $claimedMessageIds */
    private function audioMessageForUse(SubscriptionUse $use, array $claimedMessageIds): ?Message
    {
        $messageId = $use->source_type === Message::class ? $use->source_id : null;
        $messageId ??= ($use->metadata ?? [])['message_id'] ?? null;

        if ($messageId) {
            return Message::find($messageId);
        }

        if (! $use->profile_id || ! $use->used_at) {
            return null;
        }

        return Message::query()
            ->where('profile_id', $use->profile_id)
            ->where('type', 'question')
            ->whereBetween('created_at', [
                $use->used_at->copy()->subSeconds(10),
                $use->used_at->copy()->addMinutes(2),
            ])
            ->when(
                $claimedMessageIds !== [],
                fn ($query) => $query->whereNotIn('id', array_keys($claimedMessageIds))
            )
            ->get()
            ->filter(fn (Message $message): bool => $this->messageTranscriptionDuration($message) !== null
                && filled($message->data['request']['audio_url'] ?? null))
            ->sortBy(fn (Message $message): int => abs($message->created_at->diffInSeconds($use->used_at, false)))
            ->first();
    }

    /** @return array<int, true> */
    private function claimedAudioMessageIds(?int $userId): array
    {
        $claimed = [];

        SubscriptionUse::query()
            ->where('usage_type', SubscriptionUsageType::IncomingAudioMessage)
            ->where('status', '!=', SubscriptionUse::STATUS_RELEASED)
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->get(['source_type', 'source_id', 'metadata'])
            ->each(function (SubscriptionUse $use) use (&$claimed): void {
                $messageId = $use->source_type === Message::class ? $use->source_id : null;
                $messageId ??= ($use->metadata ?? [])['message_id'] ?? null;

                if ($messageId) {
                    $claimed[(int) $messageId] = true;
                }
            });

        return $claimed;
    }

    private function authoritativeAudioSeconds(SubscriptionUse $use, ?Message $message): ?int
    {
        $metadataDuration = ($use->metadata ?? [])['transcription_duration_seconds'] ?? null;
        $duration = is_numeric($metadataDuration) ? (float) $metadataDuration : null;
        $duration ??= $message ? $this->messageTranscriptionDuration($message) : null;

        if ($duration === null || ! is_finite($duration) || $duration <= 0) {
            return null;
        }

        $max = max(1, (int) config('subscriptions.audio_message_max_duration_seconds', 30));
        $tolerance = max(0.0, (float) config('subscriptions.audio_message_duration_tolerance_seconds', 0.5));
        $rounded = (int) ceil($duration);

        return $duration <= $max + $tolerance ? min($max, $rounded) : $rounded;
    }

    private function messageTranscriptionDuration(Message $message): ?float
    {
        $duration = $message->data['request']['transcription']['duration'] ?? null;

        if (! is_numeric($duration)) {
            return null;
        }

        $duration = (float) $duration;

        return is_finite($duration) && $duration > 0 ? $duration : null;
    }
}
