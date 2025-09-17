<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Voice\StoreVoiceRequest;
use Illuminate\Http\JsonResponse;

class VoiceController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/voice",
     *     summary="Create a new voice",
     *     tags={"Voice"},
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name"},
     *             @OA\Property(property="name", type="string", maxLength=100, example="Sample Voice"),
     *             @OA\Property(property="description", type="string", maxLength=500, example="Voice description")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Voice created successfully.",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Voice created successfully."),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=400, description="User already has an active voice."),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=404, description="User not found"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
    public function store(StoreVoiceRequest $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            if ($user->voices()->where('active', true)->exists()) {
                return response()->json(['message' => 'User already has an active voice.'], 400);
            }

            $voice = $user->voices()->create($request->validated());

            return response()->json(['message' => 'Voice created successfully.', 'data' => $voice], 200);

        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
