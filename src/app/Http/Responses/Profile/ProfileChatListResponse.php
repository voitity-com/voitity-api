<?php

namespace App\Http\Responses\Profile;

use App\Models\Chat;
use Illuminate\Pagination\LengthAwarePaginator;

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
}
