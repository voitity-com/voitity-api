<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\ChatAIService\AudioMessageInspector;
use App\Classes\ChatAIService\AudioTranscriptionService;
use App\Classes\ChatAIService\ChatAITextFromAudio;
use App\Classes\PublicProfiles\PublicChatSession;
use App\Classes\PublicProfiles\PublicProfileAccess;
use App\Classes\Subscriptions\ProfileMessagingCapabilitiesService;
use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Enums\SubscriptionUsageType;
use App\Events\MessageStored;
use App\Exceptions\Subscriptions\SubscriptionEntitlementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Message\StoreAudioMessageRequest;
use App\Http\Requests\Message\StoreMessageRequest;
use App\Models\Chat;
use App\Models\Message;
use App\Models\Profile;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MessageController extends Controller
{
    private const PUBLIC_REQUEST_ATTRIBUTE = 'public_profile_request';

    public function __construct(
        private readonly PublicChatSession $publicChatSessions,
        private readonly PublicProfileAccess $publicProfiles,
    ) {}

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
     *     @OA\Response(response=402, description="Subscription limit exceeded"),
     *     @OA\Response(response=404, description="Profile or chat not found"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=502, description="Answer generation failed"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function store(
        StoreMessageRequest $request,
        Profile $profile,
        SubscriptionUsageRecorder $usage,
        ProfileMessagingCapabilitiesService $capabilities,
    ): JsonResponse {
        try {
            $payload = $request->validated();
            $user = $request->user();
            $publicRequest = $this->isPublicRequest($request);

            if (! $publicRequest && ! $user instanceof User) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            return $this->storeQuestionAndProcess(
                profile: $profile,
                actor: $user instanceof User ? $user : null,
                text: $payload['message'],
                chatId: isset($payload['chat_id']) ? (int) $payload['chat_id'] : null,
                usage: $usage,
                capabilities: $capabilities,
                publicRequest: $publicRequest,
                chatSessionToken: $request->header('X-Bigmelo-Chat-Token'),
            );
        } catch (SubscriptionEntitlementException $e) {
            return $this->entitlementErrorResponse($e, $profile, $capabilities);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function publicStore(
        StoreMessageRequest $request,
        Profile $profile,
        SubscriptionUsageRecorder $usage,
        ProfileMessagingCapabilitiesService $capabilities,
    ): JsonResponse {
        $request->attributes->set(self::PUBLIC_REQUEST_ATTRIBUTE, true);

        return $this->store($request, $profile, $usage, $capabilities);
    }

    /**
     * @OA\Post(
     *     path="/api/profile/{profile}/messages/audio",
     *     summary="Send an audio message to a profile",
     *     description="Accepts a recording of up to 30 seconds, reserves chat, incoming-audio count, and incoming-audio seconds before transcription, stores the message, and generates a reply. If TTS quota is exhausted, the reply is returned as text without generated audio.",
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
     *                 @OA\Property(property="audio", type="file", format="binary", description="Audio recording of up to 30 seconds and 10 MB. Browser WebM/MP4 recordings may be detected as video/webm or video/mp4 containers."),
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
     *
     *     @OA\Response(response=202, description="Audio message stored and processing pending"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=402, description="Subscription, visitor-message, incoming-audio count, incoming-audio duration, TTS, or credit limit exceeded. Stable error codes are returned in code."),
     *     @OA\Response(response=404, description="Profile or chat not found"),
     *     @OA\Response(response=422, description="Validation error, duration unknown, duration above 30 seconds, or empty transcription"),
     *     @OA\Response(response=502, description="Audio transcription failed"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function storeAudio(
        StoreAudioMessageRequest $request,
        Profile $profile,
        AudioTranscriptionService $transcriptionService,
        AudioMessageInspector $audioInspector,
        SubscriptionUsageRecorder $usage,
        ProfileMessagingCapabilitiesService $capabilities,
    ): JsonResponse {
        $audioUsageKey = null;
        $audioUsageFinalized = false;
        $transcriptionAttempted = false;

        try {
            $payload = $request->validated();
            $user = $request->user();
            $publicRequest = $this->isPublicRequest($request);

            if (! $publicRequest && ! $user instanceof User) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            $chatId = isset($payload['chat_id']) ? (int) $payload['chat_id'] : null;
            $targetError = $this->validateMessageTarget(
                $user instanceof User ? $user : null,
                $profile,
                $chatId,
                $publicRequest,
                $request->header('X-Bigmelo-Chat-Token'),
            );

            if ($targetError instanceof JsonResponse) {
                return $targetError;
            }

            $audio = $payload['audio'] ?? null;

            if (! $audio instanceof UploadedFile) {
                return response()->json(['message' => 'The audio field is required.'], 422);
            }

            try {
                $durationSeconds = $audioInspector->durationSeconds($audio);
            } catch (\Throwable $exception) {
                Log::warning('Incoming audio rejected because its duration could not be determined.', [
                    'error' => $exception->getMessage(),
                    'profile_id' => $profile->id,
                    'size' => $audio->getSize(),
                ]);

                return response()->json([
                    'message' => 'Audio duration could not be determined.',
                    'code' => 'AUDIO_DURATION_UNKNOWN',
                    'errors' => ['audio' => ['Audio duration could not be determined.']],
                    'data' => [
                        'messaging_capabilities' => $capabilities->forProfile($profile),
                    ],
                ], 422);
            }

            $maxDuration = max(1, (int) config('subscriptions.audio_message_max_duration_seconds', 30));

            if ($durationSeconds > $maxDuration) {
                Log::notice('Incoming audio rejected because it exceeds the duration limit.', [
                    'duration_seconds' => $durationSeconds,
                    'max_duration_seconds' => $maxDuration,
                    'profile_id' => $profile->id,
                ]);

                return response()->json([
                    'message' => "Audio messages can be up to {$maxDuration} seconds.",
                    'code' => 'AUDIO_DURATION_EXCEEDED',
                    'errors' => ['audio' => ["Audio messages can be up to {$maxDuration} seconds."]],
                    'data' => [
                        'messaging_capabilities' => $capabilities->forProfile($profile),
                    ],
                ], 422);
            }

            if ($profile->user_id) {
                $audioUsageKey = 'incoming-audio:'.Str::uuid();
                $usage->reserve(
                    userId: $profile->user_id,
                    usageType: SubscriptionUsageType::IncomingAudioMessage,
                    amounts: [
                        'chat_messages' => 1,
                        'incoming_audio_messages' => 1,
                        'incoming_audio_seconds' => $durationSeconds,
                    ],
                    idempotencyKey: $audioUsageKey,
                    profileId: $profile->id,
                    metadata: [
                        'duration_seconds' => $durationSeconds,
                        'mime_type' => $audio->getMimeType(),
                        'size' => $audio->getSize(),
                    ],
                );
            }

            $transcriptionAttempted = true;
            $transcription = $transcriptionService->transcribe($audio);

            if ($audioUsageKey) {
                $usage->finalize($audioUsageKey, [
                    'transcription_source' => $transcription->source,
                    'transcription_status' => $transcription->status,
                ]);
                $audioUsageFinalized = true;
            }

            if ($transcription->isFailed()) {
                return response()->json([
                    'message' => 'Audio transcription failed.',
                    'code' => 'AUDIO_TRANSCRIPTION_FAILED',
                    'data' => array_merge($this->transcriptionFailurePayload($transcription), [
                        'messaging_capabilities' => $capabilities->forProfile($profile),
                    ]),
                ], 502);
            }

            $text = trim($transcription->text);

            if ($text === '') {
                return response()->json([
                    'message' => 'Audio transcription did not produce text.',
                    'code' => 'AUDIO_TRANSCRIPTION_EMPTY',
                    'data' => array_merge($this->transcriptionFailurePayload($transcription), [
                        'messaging_capabilities' => $capabilities->forProfile($profile),
                    ]),
                ], 422);
            }

            $audioUrl = $this->storeUploadedAudio($audio, $profile);

            return $this->storeQuestionAndProcess(
                profile: $profile,
                actor: $user instanceof User ? $user : null,
                text: $text,
                chatId: $chatId,
                audioUrl: $audioUrl,
                includeRequestMetadata: true,
                requestData: [
                    'audio_url' => $audioUrl,
                    'transcription' => $this->transcriptionPayload($transcription),
                ],
                usage: $usage,
                capabilities: $capabilities,
                usageReservationKey: $audioUsageKey,
                publicRequest: $publicRequest,
                chatSessionToken: $request->header('X-Bigmelo-Chat-Token'),
            );
        } catch (SubscriptionEntitlementException $e) {
            return $this->entitlementErrorResponse($e, $profile, $capabilities);
        } catch (\Throwable $e) {
            if ($audioUsageKey && ! $audioUsageFinalized) {
                if ($transcriptionAttempted) {
                    $usage->finalize($audioUsageKey, [
                        'transcription_status' => 'exception',
                        'error' => $e->getMessage(),
                    ]);
                } else {
                    $usage->release($audioUsageKey);
                }
            }

            Log::error('Incoming audio message failed.', [
                'error' => $e->getMessage(),
                'profile_id' => $profile->id,
                'usage_key' => $audioUsageKey,
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function publicStoreAudio(
        StoreAudioMessageRequest $request,
        Profile $profile,
        AudioTranscriptionService $transcriptionService,
        AudioMessageInspector $audioInspector,
        SubscriptionUsageRecorder $usage,
        ProfileMessagingCapabilitiesService $capabilities,
    ): JsonResponse {
        $request->attributes->set(self::PUBLIC_REQUEST_ATTRIBUTE, true);

        return $this->storeAudio(
            $request,
            $profile,
            $transcriptionService,
            $audioInspector,
            $usage,
            $capabilities,
        );
    }

    private function storeQuestionAndProcess(
        Profile $profile,
        ?User $actor,
        string $text,
        SubscriptionUsageRecorder $usage,
        ProfileMessagingCapabilitiesService $capabilities,
        ?int $chatId = null,
        ?string $audioUrl = null,
        bool $includeRequestMetadata = false,
        array $requestData = [],
        ?string $usageReservationKey = null,
        bool $publicRequest = false,
        ?string $chatSessionToken = null,
    ): JsonResponse {
        $targetError = $this->validateMessageTarget(
            $actor,
            $profile,
            $chatId,
            $publicRequest,
            $chatSessionToken,
        );

        if ($targetError instanceof JsonResponse) {
            return $targetError;
        }

        $ownsReservation = false;

        if ($profile->user_id && ! $usageReservationKey) {
            $usageReservationKey = 'chat-message:'.Str::uuid();
            $usage->reserve(
                userId: $profile->user_id,
                usageType: SubscriptionUsageType::ChatMessageReceived,
                amounts: ['chat_messages' => 1],
                idempotencyKey: $usageReservationKey,
                profileId: $profile->id,
            );
            $ownsReservation = true;
        }

        try {
            $isNewChat = $chatId === null;
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

            if ($usageReservationKey) {
                $usage->finalize(
                    $usageReservationKey,
                    ['message_id' => $message->id],
                    Message::class,
                    (string) $message->id,
                );
            }
        } catch (\Throwable $exception) {
            if ($ownsReservation && $usageReservationKey) {
                $usage->release($usageReservationKey);
            }

            throw $exception;
        }

        $nextChatSessionToken = $publicRequest
            ? $this->publicChatSessions->issue($profile, $chat)
            : null;

        $this->notifyMessageReceived($profile, $actor, $chat->id, $isNewChat);

        $event = new MessageStored($message);
        event($event);

        $answer = $event->answer;
        $message->refresh();

        if (! $answer) {
            $failure = $this->messageProcessingFailurePayload($message);

            if ($failure !== null) {
                if ($includeRequestMetadata) {
                    $failure = $this->appendRequestMetadata($failure, $message);
                }
                $failure['messaging_capabilities'] = $capabilities->forProfile($profile);
                $failure = $this->withChatSessionToken($failure, $nextChatSessionToken);

                return response()->json([
                    'message' => 'Message answer generation failed.',
                    'data' => $failure,
                ], 502);
            }

            $data = [
                'chat_id' => $chat->id,
                'message_id' => $message->id,
                'text' => null,
                'audio_url' => null,
            ];

            if ($includeRequestMetadata) {
                $data = $this->appendRequestMetadata($data, $message);
            }
            $data['messaging_capabilities'] = $capabilities->forProfile($profile);
            $data = $this->withChatSessionToken($data, $nextChatSessionToken);

            return response()->json([
                'message' => 'Message stored, processing pending.',
                'data' => $data,
            ], 202);
        }

        $data = $answer->toArray();

        if ($includeRequestMetadata) {
            $data = $this->appendRequestMetadata($data, $message);
        }
        $data['messaging_capabilities'] = $capabilities->forProfile($profile);
        $data = $this->withChatSessionToken($data, $nextChatSessionToken);

        return response()->json([
            'message' => 'Message processed successfully.',
            'data' => $data,
        ]);
    }

    private function messageProcessingFailurePayload(Message $message): ?array
    {
        $data = $message->data ?? [];
        $chatAIError = isset($data['chat_ai_error']) && is_array($data['chat_ai_error'])
            ? $data['chat_ai_error']
            : null;
        $processingError = isset($data['processing_error']) && is_string($data['processing_error'])
            ? $data['processing_error']
            : null;

        if ($chatAIError === null && $processingError === null) {
            return null;
        }

        return [
            'chat_id' => $message->chat_id,
            'message_id' => $message->id,
            'text' => null,
            'audio_url' => null,
            'source' => $chatAIError['source'] ?? null,
            'status' => $chatAIError['status'] ?? 'failed',
            'error' => $processingError ?? 'Message answer generation failed.',
            'chat_ai' => $chatAIError,
        ];
    }

    private function appendRequestMetadata(array $data, Message $message): array
    {
        $data['request_message_id'] = $message->id;
        $data['request_text'] = $message->text;
        $data['request_audio_url'] = $message->audio;

        return $data;
    }

    private function entitlementErrorResponse(
        SubscriptionEntitlementException $exception,
        Profile $profile,
        ProfileMessagingCapabilitiesService $capabilities,
    ): JsonResponse {
        return response()->json([
            'message' => $exception->getMessage(),
            'code' => $exception->errorCode(),
            'errors' => $exception->errors(),
            'data' => [
                'messaging_capabilities' => $capabilities->forProfile($profile),
            ],
        ], $exception->statusCode());
    }

    private function validateMessageTarget(
        ?User $user,
        Profile $profile,
        ?int $chatId,
        bool $publicRequest = false,
        ?string $chatSessionToken = null,
    ): ?JsonResponse {
        if ($publicRequest) {
            if (! $this->publicProfiles->isVisible($profile)) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }
        } elseif (! $user instanceof User || ! $this->userCanMessageProfile($user, $profile)) {
            return response()->json(['message' => 'Profile not found.'], 404);
        }

        if ($chatId !== null) {
            $chatExists = $profile->chats()->whereKey($chatId)->exists();

            if (! $chatExists) {
                if (! $publicRequest) {
                    return response()->json(['message' => 'Chat not found.'], 404);
                }

                return response()->json([
                    'message' => 'Chat not found.',
                    'code' => 'CHAT_SESSION_INVALID',
                ], 404);
            }

            if (
                $publicRequest
                && ! $this->publicChatSessions->isValid(
                    $chatSessionToken,
                    $profile,
                    $chatId,
                )
            ) {
                return response()->json([
                    'message' => 'Chat not found.',
                    'code' => 'CHAT_SESSION_INVALID',
                ], 404);
            }
        }

        return null;
    }

    private function userCanMessageProfile(User $user, Profile $profile): bool
    {
        return in_array($user->role, ['admin', 'api'], true) || (int) $profile->user_id === (int) $user->id;
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

    private function notifyMessageReceived(Profile $profile, ?User $actor, int $chatId, bool $isNewChat): void
    {
        $profile->loadMissing('user');

        if (! $profile->user instanceof User) {
            return;
        }

        $data = [
            'profile' => $profile->name ?: "Profile {$profile->id}",
            'profile_id' => $profile->id,
            'chat_id' => $chatId,
            'action_url' => "/dashboard/profiles/{$profile->id}/chats/{$chatId}",
        ];
        $dispatcher = app(NotificationDispatcher::class);

        if ($isNewChat) {
            $dispatcher->sendInApp($profile->user, 'new_chat_received', $data);
        }

        if (
            ! $actor instanceof User
            || (int) $actor->id !== (int) $profile->user_id
            || $actor->role === 'api'
        ) {
            $dispatcher->sendInApp($profile->user, 'new_visitor_message_received', $data);
        }
    }

    private function isPublicRequest(StoreMessageRequest|StoreAudioMessageRequest $request): bool
    {
        return (bool) $request->attributes->get(self::PUBLIC_REQUEST_ATTRIBUTE, false);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withChatSessionToken(array $data, ?string $token): array
    {
        if (filled($token)) {
            $data['chat_token'] = $token;
        }

        return $data;
    }
}
