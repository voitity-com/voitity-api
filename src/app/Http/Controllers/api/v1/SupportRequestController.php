<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\StoreSupportRequest;
use App\Models\User;
use App\Services\SupportRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class SupportRequestController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/support-requests",
     *     summary="Create an authenticated support request",
     *     tags={"Support"},
     *     security={{"sanctum":{}}},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"description"},
     *
     *             @OA\Property(property="profile_id", type="integer", nullable=true, example=1),
     *             @OA\Property(property="description", type="string", minLength=10, maxLength=3000, example="Necesito ayuda para publicar mi perfil.")
     *         )
     *     ),
     *
     *     @OA\Response(response=201, description="Support request created"),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Missing support:create ability"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=429, description="Too many requests")
     * )
     */
    public function store(StoreSupportRequest $request, SupportRequestService $service): JsonResponse
    {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        try {
            $supportRequest = $service->store($user, $request->validated(), $request);

            return response()->json([
                'message' => 'Support request received successfully.',
                'data' => [
                    'id' => $supportRequest->id,
                ],
            ], 201);
        } catch (Throwable $e) {
            Log::error('Support request creation failed.', [
                'user_id' => $user->id,
                'profile_id' => $request->validated('profile_id'),
                'message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
