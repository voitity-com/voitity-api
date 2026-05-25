<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreProfileDataRequest;
use App\Http\Requests\Profile\StoreProfileRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProfileController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/profile",
     *     summary="Create a new profile",
     *     tags={"Profile"},
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name","description","genre","personality"},
     *             @OA\Property(property="name", type="string", maxLength=100, example="John Doe"),
     *             @OA\Property(property="description", type="string", maxLength=500, example="A short bio"),
     *             @OA\Property(property="genre", type="string", maxLength=10, example="male"),
     *             @OA\Property(property="personality", type="string", maxLength=200, example="friendly"),
     *             @OA\Property(property="active", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Profile created successfully.",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Profile created successfully."),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(StoreProfileRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            $profile = $user->profiles()->create($request->validated());

            return response()->json(['message' => 'Profile created successfully.', 'data' => $profile], 200);

        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/profile/{profile}",
     *     summary="Get a profile",
     *     tags={"Profile"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="profile",
     *         in="path",
     *         required=true,
     *         description="Profile ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Profile retrieved successfully.",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Profile retrieved successfully."),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="user_id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="John Doe"),
     *                 @OA\Property(property="description", type="string", example="A short bio"),
     *                 @OA\Property(property="genre", type="string", example="male"),
     *                 @OA\Property(property="personality", type="string", example="friendly"),
     *                 @OA\Property(property="active", type="boolean", example=true),
     *                 @OA\Property(property="data", type="object", nullable=true),
     *                 @OA\Property(property="created_at", type="string", format="date-time"),
     *                 @OA\Property(property="updated_at", type="string", format="date-time")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="Profile not found"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function show(Request $request, Profile $profile): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            if (!$profile || $profile->user_id !== $user->id) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            return response()->json(['message' => 'Profile retrieved successfully.', 'data' => $profile], 200);

        } catch (\Throwable $e) {
            Log::error('Error retrieving profile.', [
                'profile_id' => $profile->id ?? null,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * @OA\Patch(
     *     path="/api/profile/{id}",
     *     summary="Update a profile",
     *     tags={"Profile"},
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="Profile ID",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", maxLength=100, example="John Doe"),
     *             @OA\Property(property="description", type="string", maxLength=500, example="A short bio"),
     *             @OA\Property(property="genre", type="string", maxLength=10, example="male"),
     *             @OA\Property(property="personality", type="string", maxLength=200, example="friendly"),
     *             @OA\Property(property="active", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Profile updated successfully.",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Profile updated successfully."),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Unauthorized"),
     *     @OA\Response(response=404, description="Profile not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function update(UpdateProfileRequest $request, Profile $profile): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            if (!$profile || $profile->user_id !== $user->id) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            $profile->update($request->validated());

            return response()->json(['message' => 'Profile updated successfully.', 'data' => $profile], 200);

        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

        /**
         * @OA\Put(
         *     path="/api/profile/{profile}/data",
         *     summary="Update the data field of a profile",
         *     tags={"Profile"},
         *     security={{"sanctum":{}}},
         *     @OA\Parameter(
         *         name="profile",
         *         in="path",
         *         required=true,
         *         description="Profile ID",
         *         @OA\Schema(type="integer")
         *     ),
         *     @OA\RequestBody(
         *         required=true,
         *         @OA\JsonContent(
         *             required={"data"},
         *             @OA\Property(property="data", type="object", example={"me": {"bio": "text"}, "work": {"company": "Acme"}})
         *         )
         *     ),
         *     @OA\Response(
         *         response=200,
         *         description="Profile updated successfully.",
         *         @OA\JsonContent(
         *             @OA\Property(property="message", type="string", example="Profile updated successfully."),
         *             @OA\Property(property="data", type="object")
         *         )
         *     ),
         *     @OA\Response(response=401, description="Unauthenticated"),
         *     @OA\Response(response=403, description="Unauthorized"),
         *     @OA\Response(response=404, description="Profile not found"),
         *     @OA\Response(response=422, description="Validation error")
         * )
         */
    public function updateData(StoreProfileDataRequest $request, Profile $profile): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            if (!$profile || $profile->user_id !== $user->id) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            $profile->update($request->validated());

            return response()->json(['message' => 'Profile updated successfully.', 'data' => $profile], 200);

        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
