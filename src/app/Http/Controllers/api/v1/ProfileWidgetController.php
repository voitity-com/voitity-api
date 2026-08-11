<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateProfileWidgetRequest;
use App\Http\Responses\Profile\ProfileWidgetResponse;
use App\Models\Profile;
use App\Models\User;
use App\Services\ProfileWidgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProfileWidgetController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/profile/{profile}/widget",
     *     tags={"Profile"},
     *     summary="Get the embeddable widget settings for a profile",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="profile", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="Widget settings retrieved"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Profile not found")
     * )
     */
    public function show(Request $request, Profile $profile, ProfileWidgetService $widgets): JsonResponse
    {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $widget = $widgets->ensureForProfile($profile);

        Log::info('Profile widget settings retrieved.', [
            'actor_user_id' => $request->user()?->id,
            'profile_id' => $profile->id,
            'profile_widget_id' => $widget->id,
            'enabled' => (bool) $widget->enabled,
            'created' => $widget->wasRecentlyCreated,
        ]);

        return response()->json([
            'message' => 'Profile widget settings retrieved successfully.',
            'data' => [
                'widget' => (new ProfileWidgetResponse($widget))->toArray(),
            ],
        ]);
    }

    /**
     * @OA\Patch(
     *     path="/api/profile/{profile}/widget",
     *     tags={"Profile"},
     *     summary="Enable or disable the embeddable profile widget",
     *     security={{"sanctum":{}}},
     *
     *     @OA\Parameter(name="profile", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"enabled"}, @OA\Property(property="enabled", type="boolean"))),
     *
     *     @OA\Response(response=200, description="Widget settings updated"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="Profile not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(
        UpdateProfileWidgetRequest $request,
        Profile $profile,
        ProfileWidgetService $widgets,
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $widget = $widgets->ensureForProfile($profile);
        $previousEnabled = (bool) $widget->enabled;
        $widget->enabled = (bool) $request->validated('enabled');
        $widget->save();

        Log::info('Profile widget settings updated.', [
            'actor_user_id' => $request->user()?->id,
            'profile_id' => $profile->id,
            'profile_widget_id' => $widget->id,
            'previous_enabled' => $previousEnabled,
            'enabled' => (bool) $widget->enabled,
        ]);

        return response()->json([
            'message' => 'Profile widget settings updated successfully.',
            'data' => [
                'widget' => (new ProfileWidgetResponse($widget))->toArray(),
            ],
        ]);
    }

    private function authorizeProfile(Request $request, Profile $profile): ?JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->role === 'admin' || (int) $profile->user_id === (int) $user->id) {
            return null;
        }

        Log::notice('Profile widget access rejected.', [
            'actor_user_id' => $user->id,
            'profile_id' => $profile->id,
        ]);

        return response()->json(['message' => 'Profile not found.'], 404);
    }
}
