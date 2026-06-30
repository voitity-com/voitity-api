<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\ChatAIService\ChatAIClient;
use App\Classes\ChatAIService\ChatAITextFromAudio;
use App\Events\MessageStored;
use App\Http\Controllers\Controller;
use App\Http\Requests\Message\StoreAudioMessageRequest;
use App\Http\Requests\Message\StoreMessageRequest;
use App\Models\Chat;
use App\Models\Message;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MessageController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/profile/{profile}/messages",
     *     summary="Send a message to a profile",
     *     description="Stores a user message and triggers the AI workflow to generate a reply.",
     *     tags={"Messages"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="profile",
     *         in="path",
     *         required=true,
     *         description="Profile identifier",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"message"},
     *
     *             @OA\Property(property="message", type="string", example="Can you summarize my notes?"),
     *             @OA\Property(property="chat_id", type="integer", nullable=true, example=12, description="Existing chat identifier")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Message processed successfully",
     *
     *         @OA\JsonContent(
     *
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
     *
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
            $user = $request->user();

            if (! $user instanceof User) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            return $this->storeQuestionAndProcess(
                profile: $profile,
                user: $user,
                text: $payload['message'],
                chatId: isset($payload['chat_id']) ? (int) $payload['chat_id'] : null,
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/profile/{profile}/messages/audio",
     *     summary="Send an audio message to a profile",
     *     description="Transcribes a user audio recording, stores it as a message, and triggers the AI workflow to generate a reply.",
     *     tags={"Messages"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Parameter(
     *         name="profile",
     *         in="path",
     *         required=true,
     *         description="Profile identifier",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *
     *             @OA\Schema(
     *                 required={"audio"},
     *
     *                 @OA\Property(property="audio", type="file", format="binary", description="Audio recording to transcribe. Browser WebM/MP4 recordings may be detected as video/webm or video/mp4 containers."),
     *                 @OA\Property(property="chat_id", type="integer", nullable=true, example=12, description="Existing chat identifier")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Audio message processed successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Message processed successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="chat_id", type="integer", example=42),
     *                 @OA\Property(property="message_id", type="integer", example=1051, description="Answer message identifier"),
     *                 @OA\Property(property="text", type="string", example="Here is the information you requested."),
     *                 @OA\Property(property="audio_url", type="string", nullable=true, example="https://cdn.example.com/audio/answer.mp3"),
     *                 @OA\Property(property="request_message_id", type="integer", example=1050, description="Stored user audio message identifier"),
     *                 @OA\Property(property="request_text", type="string", example="Necesito ayuda con mi perfil", description="Transcribed text from the uploaded audio"),
     *                 @OA\Property(property="request_audio_url", type="string", nullable=true, example="https://cdn.example.com/messages/audio/1/recording.webm")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=202, description="Audio message stored and processing pending"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Profile or chat not found"),
     *     @OA\Response(response=422, description="Validation error or empty transcription"),
     *     @OA\Response(response=502, description="Audio transcription failed"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function storeAudio(
        StoreAudioMessageRequest $request,
        Profile $profile,
        ChatAIClient $chatAIClient
    ): JsonResponse {
        try {
            $payload = $request->validated();
            $user = $request->user();

            if (! $user instanceof User) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            $chatId = isset($payload['chat_id']) ? (int) $payload['chat_id'] : null;
            $targetError = $this->validateMessageTarget($user, $profile, $chatId);

            if ($targetError instanceof JsonResponse) {
                return $targetError;
            }

            $audio = $payload['audio'] ?? null;

            if (! $audio instanceof UploadedFile) {
                return response()->json(['message' => 'The audio field is required.'], 422);
            }

            $transcription = $this->transcribeUploadedAudio($chatAIClient, $audio);

            if ($transcription->isFailed()) {
                return response()->json([
                    'message' => 'Audio transcription failed.',
                    'data' => $this->transcriptionFailurePayload($transcription),
                ], 502);
            }

            $text = trim($transcription->text);

            if ($text === '') {
                return response()->json([
                    'message' => 'Audio transcription did not produce text.',
                    'data' => $this->transcriptionFailurePayload($transcription),
                ], 422);
            }

            $audioUrl = $this->storeUploadedAudio($audio, $profile);

            return $this->storeQuestionAndProcess(
                profile: $profile,
                user: $user,
                text: $text,
                chatId: $chatId,
                audioUrl: $audioUrl,
                includeRequestMetadata: true,
                requestData: [
                    'audio_url' => $audioUrl,
                    'transcription' => $this->transcriptionPayload($transcription),
                ],
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    private function storeQuestionAndProcess(
        Profile $profile,
        User $user,
        string $text,
        ?int $chatId = null,
        ?string $audioUrl = null,
        bool $includeRequestMetadata = false,
        array $requestData = [],
    ): JsonResponse {
        $targetError = $this->validateMessageTarget($user, $profile, $chatId);

        if ($targetError instanceof JsonResponse) {
            return $targetError;
        }

        $chat = $chatId
            ? $profile->chats()->find($chatId)
            : $profile->chats()->create();

        if (! $chat instanceof Chat) {
            throw new RuntimeException('Unable to resolve chat for the provided profile.');
        }

        $requestPayload = array_merge(['message' => $text], $requestData);

        $message = $chat->messages()->create([
            'profile_id' => $profile->id,
            'text' => $text,
            'type' => 'question',
            'source' => 'api',
            'audio' => $audioUrl,
            'data' => [
                'request' => $requestPayload,
                'processing' => false,
            ],
        ]);

        if (! $message instanceof Message) {
            throw new RuntimeException('Unable to store message.');
        }

        $event = new MessageStored($message);
        event($event);

        $answer = $event->answer;

        if (! $answer) {
            $data = [
                'chat_id' => $chat->id,
                'message_id' => $message->id,
                'text' => null,
                'audio_url' => null,
            ];

            if ($includeRequestMetadata) {
                $data = $this->appendRequestMetadata($data, $message);
            }

            return response()->json([
                'message' => 'Message stored, processing pending.',
                'data' => $data,
            ], 202);
        }

        $data = $answer->toArray();

        if ($includeRequestMetadata) {
            $data = $this->appendRequestMetadata($data, $message);
        }

        return response()->json([
            'message' => 'Message processed successfully.',
            'data' => $data,
        ]);
    }

    private function appendRequestMetadata(array $data, Message $message): array
    {
        $data['request_message_id'] = $message->id;
        $data['request_text'] = $message->text;
        $data['request_audio_url'] = $message->audio;

        return $data;
    }

    private function validateMessageTarget(User $user, Profile $profile, ?int $chatId): ?JsonResponse
    {
        if (! $this->userCanMessageProfile($user, $profile)) {
            return response()->json(['message' => 'Profile not found.'], 404);
        }

        if ($chatId !== null) {
            $chatExists = $profile->chats()->whereKey($chatId)->exists();

            if (! $chatExists) {
                return response()->json(['message' => 'Chat not found.'], 404);
            }
        }

        return null;
    }

    private function userCanMessageProfile(User $user, Profile $profile): bool
    {
        return in_array($user->role, ['admin', 'api'], true) || (int) $profile->user_id === (int) $user->id;
    }

    private function transcribeUploadedAudio(ChatAIClient $chatAIClient, UploadedFile $audio): ChatAITextFromAudio
    {
        $tempBasePath = tempnam(sys_get_temp_dir(), 'message_audio_');

        if ($tempBasePath === false) {
            throw new RuntimeException('Unable to create temporary audio file for transcription.');
        }

        $extension = trim($audio->getClientOriginalExtension(), '.');
        $tempAudioPath = $extension !== '' ? "{$tempBasePath}.{$extension}" : $tempBasePath;

        if ($tempAudioPath !== $tempBasePath && ! rename($tempBasePath, $tempAudioPath)) {
            @unlink($tempBasePath);
            throw new RuntimeException('Unable to prepare temporary audio file for transcription.');
        }

        $sourcePath = $audio->getRealPath() ?: $audio->getPathname();

        if (! is_string($sourcePath) || ! copy($sourcePath, $tempAudioPath)) {
            @unlink($tempAudioPath);
            throw new RuntimeException('Unable to copy audio file for transcription.');
        }

        try {
            return $chatAIClient->getTextFromAudio($tempAudioPath);
        } finally {
            @unlink($tempAudioPath);
        }
    }

    private function storeUploadedAudio(UploadedFile $audio, Profile $profile): string
    {
        $diskName = $this->audioMessageDisk();
        $folder = trim($this->audioMessageFolder().'/'.$profile->id, '/');
        $path = $audio->store($folder, [
            'disk' => $diskName,
            'visibility' => $this->audioMessageVisibility(),
        ]);

        if (! is_string($path)) {
            throw new RuntimeException('Unable to store audio message.');
        }

        return Storage::disk($diskName)->url($path);
    }

    private function transcriptionPayload(ChatAITextFromAudio $transcription): array
    {
        return [
            'source' => $transcription->source,
            'status' => $transcription->status,
            'confidence' => $transcription->confidence,
            'detected_language' => $transcription->detectedLanguage,
            'duration' => $transcription->duration,
            'word_count' => $transcription->getWordCount(),
        ];
    }

    private function transcriptionFailurePayload(ChatAITextFromAudio $transcription): array
    {
        return [
            'source' => $transcription->source,
            'status' => $transcription->status,
        ];
    }

    private function audioMessageDisk(): string
    {
        return (string) config('chatai.audio_messages.disk', 'public');
    }

    private function audioMessageFolder(): string
    {
        $folder = trim((string) config('chatai.audio_messages.folder', 'messages/audio'), '/');

        return $folder !== '' ? $folder : 'messages/audio';
    }

    private function audioMessageVisibility(): string
    {
        return (string) config('chatai.audio_messages.visibility', 'public');
    }
}
