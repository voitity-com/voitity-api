<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\Repositories\AvatarRepository;
use App\Classes\Subscriptions\SubscriptionEntitlementService;
use App\Exceptions\Avatar\AvatarGenerationInProgressException;
use App\Exceptions\Subscriptions\SubscriptionEntitlementException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Avatar\GenerateAvatarRequest;
use App\Models\AiImage;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AvatarController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/avatar/generate",
     *     summary="Generate an avatar from a profile image",
     *     tags={"Avatar"},
     *     security={{"sanctum":{"avatar:write"}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *
     *             @OA\Schema(
     *                 required={"profile_id","image"},
     *
     *                 @OA\Property(property="profile_id", type="integer", example=1),
     *                 @OA\Property(property="image", type="string", format="binary")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=200, description="Avatar generation started successfully."),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="Profile not found"),
     *     @OA\Response(response=402, description="Subscription limit exceeded"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function generateAvatar(
        GenerateAvatarRequest $request,
        AvatarRepository $avatarRepository,
        SubscriptionEntitlementService $entitlements
    ): JsonResponse {
        $profile = null;

        try {
            $user = $request->user();

            if (! $user instanceof User) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            $profile = Profile::find((int) $request->validated('profile_id'));

            if (! $profile || ! $this->userCanGenerateAvatarForProfile($user, $profile)) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            $entitlements->assertCanUse($profile->user_id ?: $user->id, [
                'avatar_images' => 1,
                'avatar_video_seconds' => $this->avatarVideoSeconds(),
            ]);

            $aiImage = $avatarRepository->generateAvatar($user, $profile, $request->file('image'));
            $avatar = ProfileAvatar::with(['aiImage', 'aiVideo'])
                ->where('aiimage_id', $aiImage->id)
                ->first();

            $this->notifyProfileOwner($profile, 'avatar_generation_started');

            return response()->json([
                'message' => 'Avatar generation started successfully.',
                'data' => $this->aiImageToArray($aiImage, $avatar),
            ], 200);
        } catch (AvatarGenerationInProgressException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (SubscriptionEntitlementException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], $e->statusCode());
        } catch (\Throwable $e) {
            if ($profile instanceof Profile) {
                $this->notifyProfileOwner($profile, 'avatar_generation_failed', [
                    'reason' => $e->getMessage(),
                ]);
            }

            Log::error('Error generating avatar.', [
                'user_id' => $request->user()?->id,
                'profile_id' => $request->input('profile_id'),
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/avatar/{profile}",
     *     summary="Get active profile avatar",
     *     tags={"Avatar"},
     *     security={{"sanctum":{"avatar:read"}}},
     *
     *     @OA\Parameter(
     *         name="profile",
     *         in="path",
     *         required=true,
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(response=200, description="Avatar retrieved successfully."),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="Profile or avatar not found"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function show(Request $request, Profile $profile, AvatarRepository $avatarRepository): JsonResponse
    {
        try {
            $user = $request->user();

            if (! $user instanceof User) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            $avatar = $avatarRepository->getActiveAvatarForProfile($profile);
            $processingAvatar = $avatarRepository->getProcessingAvatarForProfile($profile);

            if (! $avatar) {
                return response()->json(['message' => 'Avatar not found.'], 404);
            }

            $data = $this->profileAvatarToArray($avatar);
            $data['has_processing_avatar'] = (bool) $processingAvatar;
            $data['processing_avatar'] = $processingAvatar ? $this->profileAvatarToArray($processingAvatar) : null;

            return response()->json([
                'message' => 'Avatar retrieved successfully.',
                'data' => $data,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Error retrieving avatar.', [
                'user_id' => $request->user()?->id,
                'profile_id' => $profile->id ?? null,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function history(Request $request, Profile $profile, AvatarRepository $avatarRepository): JsonResponse
    {
        try {
            $user = $request->user();

            if (! $user instanceof User) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            if (! $this->userCanGenerateAvatarForProfile($user, $profile)) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            $avatars = $avatarRepository->getAvatarHistoryForProfile($profile);
            $activeAvatar = $avatars->firstWhere('status', ProfileAvatar::STATUS_ACTIVE);
            $processingAvatar = $avatars->firstWhere('status', ProfileAvatar::STATUS_PROCESSING);

            return response()->json([
                'message' => 'Avatar history retrieved successfully.',
                'data' => [
                    'avatars' => $avatars
                        ->map(fn (ProfileAvatar $avatar) => $this->profileAvatarToArray($avatar))
                        ->values()
                        ->all(),
                    'active_avatar' => $activeAvatar ? $this->profileAvatarToArray($activeAvatar) : null,
                    'processing_avatar' => $processingAvatar ? $this->profileAvatarToArray($processingAvatar) : null,
                    'total' => $avatars->count(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Error retrieving avatar history.', [
                'user_id' => $request->user()?->id,
                'profile_id' => $profile->id ?? null,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function activate(Request $request, Profile $profile, AvatarRepository $avatarRepository): JsonResponse
    {
        try {
            $user = $request->user();

            if (! $user instanceof User) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            if (! $this->userCanGenerateAvatarForProfile($user, $profile)) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            $validated = $request->validate([
                'avatar_id' => ['required', 'integer', 'exists:profile_avatars,id'],
            ]);

            $avatar = ProfileAvatar::with(['aiImage', 'aiVideo'])
                ->where('profile_id', $profile->id)
                ->find((int) $validated['avatar_id']);

            if (! $avatar) {
                return response()->json(['message' => 'Avatar not found.'], 404);
            }

            $activeAvatar = $avatarRepository->activateAvatar($profile, $avatar);

            $this->notifyProfileOwner($profile, 'avatar_activated');

            return response()->json([
                'message' => 'Avatar activated successfully.',
                'data' => $this->profileAvatarToArray($activeAvatar),
            ], 200);
        } catch (AvatarGenerationInProgressException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (ValidationException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Error activating avatar.', [
                'user_id' => $request->user()?->id,
                'profile_id' => $profile->id ?? null,
                'avatar_id' => $request->input('avatar_id'),
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    private function userCanGenerateAvatarForProfile(User $user, Profile $profile): bool
    {
        return $user->role === 'admin' || $profile->user_id === $user->id;
    }

    private function avatarVideoSeconds(): int
    {
        $driver = (string) config('videoai.default', 'runway');

        return max(1, (int) config("videoai.drivers.{$driver}.default_duration", 5));
    }

    /**
     * @return array<string, mixed>
     */
    private function aiImageToArray(AiImage $aiImage, ?ProfileAvatar $avatar = null): array
    {
        return [
            'id' => $aiImage->id,
            'user_id' => $aiImage->user_id,
            'profile_id' => $aiImage->profile_id,
            'source_id' => $aiImage->source_id,
            'source' => $aiImage->source,
            'status' => $aiImage->status,
            'file' => $aiImage->file,
            'failure_code' => $aiImage->failure_code,
            'failure_reason' => $aiImage->failure_reason,
            'avatar' => $avatar ? $this->profileAvatarToArray($avatar) : null,
            'created_at' => $aiImage->created_at,
            'updated_at' => $aiImage->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function profileAvatarToArray(ProfileAvatar $avatar): array
    {
        return [
            'id' => $avatar->id,
            'user_id' => $avatar->user_id,
            'profile_id' => $avatar->profile_id,
            'aiimage_id' => $avatar->aiimage_id,
            'ai_video_id' => $avatar->ai_video_id,
            'file' => $avatar->file,
            'status' => $avatar->status,
            'failure_code' => $avatar->failure_code,
            'failure_reason' => $avatar->failure_reason,
            'ai_image' => $avatar->aiImage ? $this->aiImageToArray($avatar->aiImage) : null,
            'ai_video' => $avatar->aiVideo ? [
                'id' => $avatar->aiVideo->id,
                'aiimage_id' => $avatar->aiVideo->aiimage_id,
                'source_id' => $avatar->aiVideo->source_id,
                'source' => $avatar->aiVideo->source,
                'status' => $avatar->aiVideo->status,
                'file' => $avatar->aiVideo->file,
                'failure_code' => $avatar->aiVideo->failure_code,
                'failure_reason' => $avatar->aiVideo->failure_reason,
            ] : null,
            'created_at' => $avatar->created_at,
            'updated_at' => $avatar->updated_at,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function notifyProfileOwner(Profile $profile, string $key, array $data = []): void
    {
        $profile->loadMissing('user');

        if (! $profile->user instanceof User) {
            return;
        }

        app(NotificationDispatcher::class)->send($profile->user, $key, [
            'profile' => $profile->name ?: "Profile {$profile->id}",
            'profile_id' => $profile->id,
            'action_url' => "/dashboard/profiles/{$profile->id}/avatar",
            ...$data,
        ]);
    }
}
