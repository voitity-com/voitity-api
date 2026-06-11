<?php

namespace App\Http\Responses\Profile;

use App\Models\Message;
use Illuminate\Pagination\LengthAwarePaginator;

class ProfileChatMessageListResponse
{
    public function __construct(private readonly LengthAwarePaginator $messages) {}

    public function toArray(): array
    {
        return [
            'messages' => $this->messages->getCollection()
                ->map(fn (Message $message) => [
                    'id' => $message->id,
                    'profile_id' => $message->profile_id,
                    'chat_id' => $message->chat_id,
                    'text' => $message->text,
                    'type' => $message->type,
                    'source' => $message->source,
                    'audio' => $message->audio,
                    'data' => $message->data,
                    'created_at' => $message->created_at?->toJSON(),
                    'updated_at' => $message->updated_at?->toJSON(),
                ])
                ->values()
                ->all(),
            'pagination' => [
                'current_page' => $this->messages->currentPage(),
                'per_page' => $this->messages->perPage(),
                'last_page' => $this->messages->lastPage(),
                'total' => $this->messages->total(),
            ],
        ];
    }
}
