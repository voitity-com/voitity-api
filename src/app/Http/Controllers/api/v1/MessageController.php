<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Message\StoreMessageRequest;
use App\Events\MessageStored;
use App\Models\Chat;
use App\Models\Message;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;

class MessageController extends Controller
{
    public function store(StoreMessageRequest $request, Profile $profile): JsonResponse
    {
        try {
            $payload = $request->validated();

            if (isset($payload['chat_id'])) {
                $chatExists = $profile->chats()->whereKey($payload['chat_id'])->exists();

                if (!$chatExists) {
                    return response()->json(['message' => 'Chat not found.'], 404);
                }
            }

            $chat = isset($payload['chat_id'])
                ? $profile->chats()->find($payload['chat_id'])
                : $profile->chats()->create();

            if (!$chat instanceof Chat) {
                throw new \RuntimeException('Unable to resolve chat for the provided profile.');
            }

            $message = $chat->messages()->create([
                'profile_id' => $profile->id,
                'text' => $payload['message'],
                'type' => 'question',
                'source' => 'api',
                'audio' => null,
                'data' => [
                    'request' => [
                        'message' => $payload['message'],
                    ],
                    'processing' => false,
                ],
            ]);

            if (!$message instanceof Message) {
                throw new \RuntimeException('Unable to store message.');
            }

            $event = new MessageStored($message);
            event($event);

            $answer = $event->answer;

            if (!$answer) {
                return response()->json([
                    'message' => 'Message stored, processing pending.',
                    'data' => [
                        'chat_id' => $chat->id,
                        'message_id' => $message->id,
                        'text' => null,
                        'audio_url' => null,
                    ],
                ], 202);
            }

            return response()->json([
                'message' => 'Message processed successfully.',
                'data' => $answer->toArray(),
            ]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
