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
    /**
     * @OA\Post(
     *     path="/api/profile/{profile}/messages",
     *     summary="Send a message to a profile",
     *     description="Stores a user message and triggers the AI workflow to generate a reply.",
     *     tags={"Messages"},
     *     security={{"sanctum": {}}},
     *     @OA\Parameter(
     *         name="profile",
     *         in="path",
     *         required=true,
     *         description="Profile identifier",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"message"},
     *             @OA\Property(property="message", type="string", example="Can you summarize my notes?"),
     *             @OA\Property(property="chat_id", type="integer", nullable=true, example=12, description="Existing chat identifier")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Message processed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Message processed successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="chat_id", type="integer", example=42),
     *                 @OA\Property(property="message_id", type="integer", example=1050),
     *                 @OA\Property(property="text", type="string", example="Here is the information you requested."),
     *                 @OA\Property(property="audio_url", type="string", nullable=true, example="https://cdn.example.com/audio/answer.mp3"),
     *                 @OA\Property(property="source", type="string", example="openai"),
     *                 @OA\Property(property="data", type="object")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=202, description="Message stored and processing pending"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Profile or chat not found"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
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
