<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileConversationMessagesRequest;
use App\Http\Requests\Profile\UploadProfileConversationMessageAudioRequest;
use App\Models\Profile;
use App\Models\User;
use App\Services\ProfileConversationMessageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ProfileConversationMessageController extends Controller
{
    public function index(
        Request $request,
        Profile $profile,
        ProfileConversationMessageService $messages
    ): JsonResponse {
        try {
            $targetError = $this->validateProfileOwner($request, $profile);

            if ($targetError instanceof JsonResponse) {
                return $targetError;
            }

            return response()->json([
                'message' => 'Profile conversation messages retrieved successfully.',
                'data' => [
                    'messages' => $messages->resolvedMessages($profile),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Error listing profile conversation messages.', [
                'profile_id' => $profile->id,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function update(
        UpdateProfileConversationMessagesRequest $request,
        Profile $profile,
        ProfileConversationMessageService $messages
    ): JsonResponse {
        try {
            $targetError = $this->validateProfileOwner($request, $profile);

            if ($targetError instanceof JsonResponse) {
                return $targetError;
            }

            return response()->json([
                'message' => 'Profile conversation messages updated successfully.',
                'data' => [
                    'messages' => $messages->updateMessages($profile, $request->validated()),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Error updating profile conversation messages.', [
                'profile_id' => $profile->id,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function generateAudio(
        Request $request,
        Profile $profile,
        string $type,
        ProfileConversationMessageService $messages
    ): JsonResponse {
        try {
            $targetError = $this->validateProfileOwner($request, $profile);

            if ($targetError instanceof JsonResponse) {
                return $targetError;
            }

            $messages->generateAudio($profile, $type);
            $freshProfile = $profile->fresh(['conversationMessages', 'voices']);

            return response()->json([
                'message' => 'Profile conversation message audio generated successfully.',
                'data' => [
                    'message' => $messages->resolvedMessage($freshProfile, $type),
                ],
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [
                    'audio' => [$e->getMessage()],
                ],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error generating profile conversation message audio.', [
                'profile_id' => $profile->id,
                'type' => $type,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function uploadAudio(
        UploadProfileConversationMessageAudioRequest $request,
        Profile $profile,
        string $type,
        ProfileConversationMessageService $messages
    ): JsonResponse {
        try {
            $targetError = $this->validateProfileOwner($request, $profile);

            if ($targetError instanceof JsonResponse) {
                return $targetError;
            }

            $messages->uploadAudio($profile, $type, $request->file('audio'));
            $freshProfile = $profile->fresh(['conversationMessages', 'voices']);

            return response()->json([
                'message' => 'Profile conversation message audio uploaded successfully.',
                'data' => [
                    'message' => $messages->resolvedMessage($freshProfile, $type),
                ],
            ]);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => [
                    'audio' => [$e->getMessage()],
                ],
            ], 422);
        } catch (\Throwable $e) {
            Log::error('Error uploading profile conversation message audio.', [
                'profile_id' => $profile->id,
                'type' => $type,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function clearAudio(
        Request $request,
        Profile $profile,
        string $type,
        ProfileConversationMessageService $messages
    ): JsonResponse {
        try {
            $targetError = $this->validateProfileOwner($request, $profile);

            if ($targetError instanceof JsonResponse) {
                return $targetError;
            }

            $messages->clearAudio($profile, $type);
            $freshProfile = $profile->fresh(['conversationMessages', 'voices']);

            return response()->json([
                'message' => 'Profile conversation message audio removed successfully.',
                'data' => [
                    'message' => $messages->resolvedMessage($freshProfile, $type),
                ],
            ]);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Error clearing profile conversation message audio.', [
                'profile_id' => $profile->id,
                'type' => $type,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    private function validateProfileOwner(Request $request, Profile $profile): ?JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if (! in_array($user->role, ['admin', 'api'], true) && (int) $profile->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Profile not found.'], 404);
        }

        return null;
    }
}
