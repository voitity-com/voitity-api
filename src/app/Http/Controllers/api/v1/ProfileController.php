<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\StoreProfileRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    /**
     * Store a newly created profile in storage.
     *
     * @param StoreProfileRequest $request
     * @return JsonResponse
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
     * Update a specific profile
     *
     * @param UpdateProfileRequest $request
     * @param integer $id
     * @return JsonResponse
     */
    public function update(UpdateProfileRequest $request, int $id): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            $profile = $user->profiles()->find($id);

            if (!$profile) {
                return response()->json(['message' => 'Profile not found.'], 404);
            }

            $profile->update($request->validated());

            return response()->json(['message' => 'Profile updated successfully.', 'data' => $profile], 200);

        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
