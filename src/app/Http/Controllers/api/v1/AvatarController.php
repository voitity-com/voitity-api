<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\Repositories\AvatarRepository;
use App\Http\Controllers\Controller;
use App\Http\Requests\Avatar\GenerateAvatarRequest;
use App\Models\AiImage;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AvatarController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/avatar/generate",
     *     summary="Generate an avatar from a profile image",
     *     tags={"Avatar"},
     *     security={{"sanctum":{"avatar:write"}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"profile_id","image"},
     *                 @OA\Property(property="profile_id", type="integer", example=1),
     *                 @OA\Property(property="image", type="string", format="binary")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="Avatar generation started successfully."),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="Profile not found"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function generateAvatar(GenerateAvatarRequest $request, AvatarRepository $avatarRepository): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user instanceof User) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            $profile = Profile::find((int) $request->validated('profile_id'));

            if (!$profile || !$this->userCanGenerateAvatarForProfile($user, $profile)) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            $aiImage = $avatarRepository->generateAvatar($user, $profile, $request->file('image'));

            return response()->json([
                'message' => 'Avatar generation started successfully.',
                'data' => $this->aiImageToArray($aiImage),
            ], 200);
        } catch (\Throwable $e) {
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
     *     @OA\Parameter(
     *         name="profile",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
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

            if (!$user instanceof User) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            $avatar = $avatarRepository->getActiveAvatarForProfile($profile);

            if (!$avatar) {
                return response()->json(['message' => 'Avatar not found.'], 404);
            }

            return response()->json([
                'message' => 'Avatar retrieved successfully.',
                'data' => $this->profileAvatarToArray($avatar),
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

    private function userCanGenerateAvatarForProfile(User $user, Profile $profile): bool
    {
        return $user->role === 'admin' || $profile->user_id === $user->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function aiImageToArray(AiImage $aiImage): array
    {
        return [
            'id' => $aiImage->id,
            'user_id' => $aiImage->user_id,
            'profile_id' => $aiImage->profile_id,
            'source_id' => $aiImage->source_id,
            'source' => $aiImage->source,
            'status' => $aiImage->status,
            'file' => $aiImage->file,
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
            'ai_image' => $avatar->aiImage ? $this->aiImageToArray($avatar->aiImage) : null,
            'ai_video' => $avatar->aiVideo ? [
                'id' => $avatar->aiVideo->id,
                'source_id' => $avatar->aiVideo->source_id,
                'source' => $avatar->aiVideo->source,
                'status' => $avatar->aiVideo->status,
                'file' => $avatar->aiVideo->file,
            ] : null,
            'created_at' => $avatar->created_at,
            'updated_at' => $avatar->updated_at,
        ];
    }
}
