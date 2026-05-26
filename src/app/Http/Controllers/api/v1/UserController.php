<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Responses\User\UserResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/user",
     *     summary="Get authenticated user",
     *     tags={"User"},
     *     security={{"sanctum":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="User retrieved successfully.",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="User retrieved successfully."),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="id", type="integer", example=1),
     *                 @OA\Property(property="name", type="string", example="Abel Moreno"),
     *                 @OA\Property(property="first_name", type="string", nullable=true, example="Abel"),
     *                 @OA\Property(property="last_name", type="string", nullable=true, example="Moreno"),
     *                 @OA\Property(property="email", type="string", example="moreno.abel@gmail.com"),
     *                 @OA\Property(property="role", type="string", example="admin"),
     *                 @OA\Property(property="avatar", type="string", nullable=true, example="https://example.com/avatar.jpg"),
     *                 @OA\Property(property="provider", type="string", nullable=true, example="email"),
     *                 @OA\Property(property="email_verified_at", type="string", format="date-time", nullable=true),
     *                 @OA\Property(property="google_verified_at", type="string", format="date-time", nullable=true),
     *                 @OA\Property(property="created_at", type="string", format="date-time", nullable=true),
     *                 @OA\Property(property="updated_at", type="string", format="date-time", nullable=true)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=403, description="Missing user:read ability"),
     *     @OA\Response(response=404, description="User not found"),
     *     @OA\Response(response=500, description="Unexpected error")
     * )
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $user = $request->user();

            if (!$user instanceof User) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            return response()->json([
                'message' => 'User retrieved successfully.',
                'data' => (new UserResponse($user))->toArray(),
            ], 200);

        } catch (\Throwable $e) {
            Log::error('Error retrieving authenticated user.', [
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        }
    }
}
