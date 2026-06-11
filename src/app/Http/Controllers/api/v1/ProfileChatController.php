<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\Profile\ProfileChatListResponse;
use App\Http\Responses\Profile\ProfileChatMessageListResponse;
use App\Models\Chat;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProfileChatController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * @OA\Get(
     *     path="/api/profile/chats",
     *     summary="List profile chats",
     *     tags={"Messages"},
     *     security={{"sanctum":{"chat:read"}}},
     *
     *     @OA\Parameter(
     *         name="profile_id",
     *         in="query",
     *         required=true,
     *         description="Profile identifier",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         required=false,
     *         description="Pagination page",
     *
     *         @OA\Schema(type="integer", minimum=1, default=1)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Chats retrieved successfully.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Chats retrieved successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="chats",
     *                     type="array",
     *
     *                     @OA\Items(
     *                         type="object",
     *
     *                         @OA\Property(property="id", type="integer", example=12),
     *                         @OA\Property(property="created_at", type="string", format="date-time"),
     *                         @OA\Property(property="updated_at", type="string", format="date-time")
     *                     )
     *                 ),
     *                 @OA\Property(
     *                     property="pagination",
     *                     type="object",
     *                     @OA\Property(property="current_page", type="integer", example=1),
     *                     @OA\Property(property="per_page", type="integer", example=20),
     *                     @OA\Property(property="last_page", type="integer", example=2),
     *                     @OA\Property(property="total", type="integer", example=25)
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="Profile not found"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function listChats(Request $request, ?Profile $profile = null): JsonResponse
    {
        $validated = $request->validate([
            'profile_id' => [$profile ? 'nullable' : 'required', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $user = $request->user();

            if (! $user instanceof User) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            $profile = $this->resolveProfile($profile, $validated['profile_id'] ?? null);

            if (! $profile) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            if (! $this->userCanListChatsForProfile($user, $profile)) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            $chats = $profile->chats()
                ->orderByDesc('updated_at')
                ->paginate(
                    self::PER_PAGE,
                    ['id', 'profile_id', 'created_at', 'updated_at'],
                    'page',
                    (int) ($validated['page'] ?? 1)
                );

            return response()->json([
                'message' => 'Chats retrieved successfully.',
                'data' => (new ProfileChatListResponse($chats))->toArray(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Error listing profile chats.', [
                'user_id' => $request->user()?->id,
                'profile_id' => $profile->id ?? null,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/profile/chats/messages",
     *     summary="List chat messages",
     *     tags={"Messages"},
     *     security={{"sanctum":{"chat:read"}}},
     *
     *     @OA\Parameter(
     *         name="profile_id",
     *         in="query",
     *         required=true,
     *         description="Profile identifier",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Parameter(
     *         name="chat_id",
     *         in="query",
     *         required=true,
     *         description="Chat identifier",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         required=false,
     *         description="Pagination page",
     *
     *         @OA\Schema(type="integer", minimum=1, default=1)
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Messages retrieved successfully.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Messages retrieved successfully."),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="messages",
     *                     type="array",
     *
     *                     @OA\Items(
     *                         type="object",
     *
     *                         @OA\Property(property="id", type="integer", example=1050),
     *                         @OA\Property(property="profile_id", type="integer", example=1),
     *                         @OA\Property(property="chat_id", type="integer", example=12),
     *                         @OA\Property(property="text", type="string", example="Can you summarize my notes?"),
     *                         @OA\Property(property="type", type="string", example="question"),
     *                         @OA\Property(property="source", type="string", nullable=true, example="api"),
     *                         @OA\Property(property="audio", type="string", nullable=true),
     *                         @OA\Property(property="data", type="object", nullable=true),
     *                         @OA\Property(property="created_at", type="string", format="date-time"),
     *                         @OA\Property(property="updated_at", type="string", format="date-time")
     *                     )
     *                 ),
     *                 @OA\Property(
     *                     property="pagination",
     *                     type="object",
     *                     @OA\Property(property="current_page", type="integer", example=1),
     *                     @OA\Property(property="per_page", type="integer", example=20),
     *                     @OA\Property(property="last_page", type="integer", example=2),
     *                     @OA\Property(property="total", type="integer", example=25)
     *                 )
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="Profile or chat not found"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function getChatMessages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'profile_id' => ['required', 'integer', 'min:1'],
            'chat_id' => ['required', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $user = $request->user();

            if (! $user instanceof User) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            $profile = $this->resolveProfile(null, $validated['profile_id']);

            if (! $profile) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            if (! $this->userCanListChatsForProfile($user, $profile)) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            $chat = $profile->chats()->whereKey((int) $validated['chat_id'])->first();

            if (! $chat instanceof Chat) {
                return response()->json(['message' => 'Chat not found.'], 404);
            }

            $messages = $chat->messages()
                ->orderBy('created_at')
                ->orderBy('id')
                ->paginate(
                    self::PER_PAGE,
                    ['id', 'profile_id', 'chat_id', 'text', 'type', 'source', 'audio', 'data', 'created_at', 'updated_at'],
                    'page',
                    (int) ($validated['page'] ?? 1)
                );

            return response()->json([
                'message' => 'Messages retrieved successfully.',
                'data' => (new ProfileChatMessageListResponse($messages))->toArray(),
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Error listing chat messages.', [
                'user_id' => $request->user()?->id,
                'profile_id' => $request->input('profile_id'),
                'chat_id' => $request->input('chat_id'),
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    private function resolveProfile(?Profile $profile, mixed $profileId): ?Profile
    {
        if ($profile instanceof Profile) {
            return $profile;
        }

        if (! $profileId) {
            return null;
        }

        return Profile::find((int) $profileId);
    }

    private function userCanListChatsForProfile(User $user, Profile $profile): bool
    {
        return $user->role === 'admin' || $profile->user_id === $user->id;
    }
}
