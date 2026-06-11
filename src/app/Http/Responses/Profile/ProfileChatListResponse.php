<?php

namespace App\Http\Responses\Profile;

use App\Models\Chat;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;

class ProfileChatListResponse
{
    public function __construct(private readonly LengthAwarePaginator $chats) {}

    public function toArray(): array
    {
        return [
            'chats' => $this->chats->getCollection()
                ->map(fn (Chat $chat) => [
                    'id' => $chat->id,
                    'created_at' => $chat->created_at?->toJSON(),
                    'updated_at' => $chat->updated_at?->toJSON(),
                    'api_messages_count' => (int) ($chat->api_messages_count ?? 0),
                    'openai_messages_count' => (int) ($chat->openai_messages_count ?? 0),
                    'last_message_at' => $this->dateTimeToJson($chat->last_message_at ?? null),
                ])
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $this->chats->currentPage(),
                'per_page' => $this->chats->perPage(),
                'last_page' => $this->chats->lastPage(),
                'total' => $this->chats->total(),
            ],
        ];
    }

    private function dateTimeToJson(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->toJSON();
        }

        return Carbon::parse((string) $value)->toJSON();
    }
}
