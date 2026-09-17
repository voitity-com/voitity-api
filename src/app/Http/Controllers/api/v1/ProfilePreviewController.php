<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\Repositories\AvatarRepository;
use App\Enums\ProfileStatus;
use App\Http\Controllers\Controller;
use App\Http\Responses\Profile\PublicProfileAvatarResponse;
use App\Http\Responses\Profile\PublicProfileResponse;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfilePreviewController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/profile/{profile}/preview",
     *     summary="Preview a profile in the authenticated dashboard",
     *     tags={"Profile"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(
     *         name="profile",
     *         in="path",
     *         required=true,
     *         description="Profile ID",
     *
     *         @OA\Schema(type="integer")
     *     ),
     *
     *     @OA\Response(response=200, description="Profile preview retrieved successfully."),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Missing profile read ability"),
     *     @OA\Response(response=404, description="Profile not found")
     * )
     */
    public function show(
        Request $request,
        Profile $profile,
        AvatarRepository $avatars,
    ): JsonResponse {
        $user = $request->user();

        if (! $user || ($user->role !== 'admin' && (int) $profile->user_id !== (int) $user->id)) {
            return response()->json(['message' => 'Profile not found.'], 404);
        }

        $avatar = $avatars->getActiveAvatarForProfile($profile);
        $isPublished = $profile->active && $profile->status === ProfileStatus::Published;

        return response()->json([
            'message' => 'Profile preview retrieved successfully.',
            'data' => [
                'profile' => (new PublicProfileResponse($profile))->toArray(),
                'avatar' => $avatar && filled($avatar->file)
                    ? (new PublicProfileAvatarResponse($avatar))->toArray()
                    : null,
                'social_networks' => config('social-networks.networks', []),
                'preview' => [
                    'interactive' => $isPublished,
                    'is_published' => $isPublished,
                ],
            ],
        ])->header('Cache-Control', 'no-store, private');
    }
}
