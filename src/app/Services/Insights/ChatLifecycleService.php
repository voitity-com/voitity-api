<?php

namespace App\Services\Insights;

use App\Enums\ChatEndReason;
use App\Enums\ChatStatus;
use App\Events\ChatClosed;
use App\Models\Chat;
use App\Models\Profile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChatLifecycleService
{
    public function resolve(Profile $profile, ?int $chatId, ?string $visitorIdHash): array
    {
        return DB::transaction(function () use ($chatId, $profile, $visitorIdHash): array {
            $chat = $chatId ? $profile->chats()->lockForUpdate()->find($chatId) : null;

            if ($chat instanceof Chat && ! $this->isActive($chat)) {
                $this->close($chat, ChatEndReason::Inactivity);
                $chat = null;
            }

            $isNew = ! $chat instanceof Chat;

            if ($isNew) {
                $chat = $profile->chats()->create([
                    'status' => ChatStatus::Open,
                    'started_at' => now(),
                    'last_activity_at' => now(),
                    'visitor_id_hash' => $visitorIdHash,
                ]);
            } else {
                $chat->forceFill([
                    'last_activity_at' => now(),
                    'visitor_id_hash' => $chat->visitor_id_hash ?: $visitorIdHash,
                ])->save();
            }

            return [$chat, $isNew];
        });
    }

    public function closeInactive(?Carbon $at = null): int
    {
        $at ??= now();
        $cutoff = $at->copy()->subMinutes($this->inactivityMinutes());
        $closed = 0;

        Chat::query()
            ->where('status', ChatStatus::Open->value)
            ->where('last_activity_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($chats) use (&$closed): void {
                foreach ($chats as $chat) {
                    if ($chat instanceof Chat && $this->close($chat, ChatEndReason::Inactivity)) {
                        $closed++;
                    }
                }
            });

        return $closed;
    }

    public function close(Chat $chat, ChatEndReason $reason): bool
    {
        $closed = DB::transaction(function () use ($chat, $reason): ?Chat {
            $locked = Chat::query()->lockForUpdate()->find($chat->id);

            if (! $locked instanceof Chat || $locked->status === ChatStatus::Closed) {
                return null;
            }

            $endedAt = $reason === ChatEndReason::Inactivity
                ? ($locked->last_activity_at ?? $locked->started_at ?? now())->copy()->addMinutes($this->inactivityMinutes())
                : now();

            $locked->forceFill([
                'status' => ChatStatus::Closed,
                'ended_at' => $endedAt,
                'ended_reason' => $reason,
            ])->save();

            return $locked;
        });

        if (! $closed instanceof Chat) {
            return false;
        }

        Log::info('Chat closed for profile insights.', [
            'chat_id' => $closed->id,
            'profile_id' => $closed->profile_id,
            'ended_reason' => $reason->value,
            'ended_at' => $closed->ended_at?->toIso8601String(),
        ]);
        ChatClosed::dispatch($closed);

        return true;
    }

    public function isActive(Chat $chat): bool
    {
        if ($chat->status !== ChatStatus::Open) {
            return false;
        }

        $lastActivity = $chat->last_activity_at ?? $chat->started_at ?? $chat->created_at;

        return $lastActivity !== null && $lastActivity->gt(now()->subMinutes($this->inactivityMinutes()));
    }

    public function inactivityMinutes(): int
    {
        return max(1, (int) config('insights.chat_inactivity_minutes', 30));
    }
}
