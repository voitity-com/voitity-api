<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/auth/get-token",
     *     summary="Get access token",
     *     tags={"Auth"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"email","password"},
     *             @OA\Property(property="email", type="string", example="voitity@gmail.com"),
     *             @OA\Property(property="password", type="string", example="qwerty123")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful login, returns access token",
     *         @OA\JsonContent(
     *             @OA\Property(property="access_token", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Invalid credentials",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Your email or password are incorrect.")
     *         )
     *     )
     * )
     * Returns an access token.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getToken(Request $request): JsonResponse
    {
        try {
            $login = $request->validate([
                'email' => 'required|string',
                'password' => 'required|string',
            ]);

            if (!Auth::attempt($login)) {
                return response()->json(['message' => 'Your email or password are incorrect.'], 403);
            }

            
            $user = Auth::user();

            if($user->role === "forgotten") {
                $user->role = $user->active ? 'user' : 'inactive';
                $user->save();
            }

            $token = $user->createToken('token-name', $user->getRoleAbilities());

            return response()->json(
                [
                    'access_token' => $token->plainTextToken,
                ],
                200
            );

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Your email or password are incorrect.'], 403);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'An error occurred while processing your request.'], 500);
        }
    }
}
