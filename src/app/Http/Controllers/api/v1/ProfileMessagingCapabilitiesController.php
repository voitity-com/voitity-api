<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\Subscriptions\ProfileMessagingCapabilitiesService;
use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileMessagingCapabilitiesController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/profile/{profile}/messaging-capabilities",
     *     summary="Get current public messaging capabilities for a profile",
     *     tags={"Messages"},
     *     security={{"sanctum":{"profile:read"}}},
     *
     *     @OA\Parameter(name="profile", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Capabilities retrieved successfully",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="text_messages_enabled", type="boolean", example=true),
     *                 @OA\Property(property="audio_messages_enabled", type="boolean", example=true),
     *                 @OA\Property(property="audio_max_duration_seconds", type="integer", example=30),
     *                 @OA\Property(property="reason", type="string", nullable=true, example=null)
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Missing profile:read ability"),
     *     @OA\Response(response=404, description="Profile not found")
     * )
     */
    public function show(
        Request $request,
        Profile $profile,
        ProfileMessagingCapabilitiesService $capabilities,
    ): JsonResponse {
        $user = $request->user();

        if (! $user instanceof User || ! $this->canRead($user, $profile)) {
            return response()->json(['message' => 'Profile not found.'], 404);
        }

        return response()->json([
            'message' => 'Profile messaging capabilities retrieved successfully.',
            'data' => $capabilities->forProfile($profile),
        ]);
    }

    private function canRead(User $user, Profile $profile): bool
    {
        return in_array($user->role, ['admin', 'api'], true)
            || (int) $profile->user_id === (int) $user->id;
    }
}
