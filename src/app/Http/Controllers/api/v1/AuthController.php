<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleOAuthRequest;
use App\Services\GoogleOAuthService;
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

            if (!($user instanceof \App\Models\User)) {
                return response()->json(['message' => 'User not found.'], 404);
            }

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

    /**
     * @OA\Post(
     *     path="/api/auth/google/sign-in",
     *     summary="Sign in with Google OAuth",
     *     tags={"Auth"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"google_id","email","name","first_name","last_name","access_token"},
     *             @OA\Property(property="google_id", type="string", example="123456789012345678901"),
     *             @OA\Property(property="email", type="string", example="user@gmail.com"),
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="first_name", type="string", example="John"),
     *             @OA\Property(property="last_name", type="string", example="Doe"),
     *             @OA\Property(property="avatar", type="string", nullable=true, example="https://lh3.googleusercontent.com/a/photo.jpg"),
     *             @OA\Property(property="access_token", type="string", example="ya29.a0AfH6SMC...")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful Google sign in",
     *         @OA\JsonContent(
     *             @OA\Property(property="access_token", type="string"),
     *             @OA\Property(property="user", type="object",
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="email", type="string"),
     *                 @OA\Property(property="first_name", type="string"),
     *                 @OA\Property(property="last_name", type="string"),
     *                 @OA\Property(property="avatar", type="string", nullable=true),
     *                 @OA\Property(property="provider", type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Invalid Google token",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Invalid Google access token.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="User not found"
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function googleSignIn(GoogleOAuthRequest $request, GoogleOAuthService $googleService): JsonResponse
    {
        return $this->handleGoogleOAuth($request, $googleService, false, 'User not found. Please sign up.');
    }

    /**
     * @OA\Post(
     *     path="/api/auth/google/sign-up",
     *     summary="Sign up with Google OAuth",
     *     tags={"Auth"},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"google_id","email","name","first_name","last_name","access_token"},
     *             @OA\Property(property="google_id", type="string", example="123456789012345678901"),
     *             @OA\Property(property="email", type="string", example="user@gmail.com"),
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="first_name", type="string", example="John"),
     *             @OA\Property(property="last_name", type="string", example="Doe"),
     *             @OA\Property(property="avatar", type="string", nullable=true, example="https://lh3.googleusercontent.com/a/photo.jpg"),
     *             @OA\Property(property="access_token", type="string", example="ya29.a0AfH6SMC...")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful Google sign up",
     *         @OA\JsonContent(
     *             @OA\Property(property="access_token", type="string"),
     *             @OA\Property(property="user", type="object",
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="email", type="string"),
     *                 @OA\Property(property="first_name", type="string"),
     *                 @OA\Property(property="last_name", type="string"),
     *                 @OA\Property(property="avatar", type="string", nullable=true),
     *                 @OA\Property(property="provider", type="string")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Invalid Google token",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Invalid Google access token.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function googleSignUp(GoogleOAuthRequest $request, GoogleOAuthService $googleService): JsonResponse
    {
        return $this->handleGoogleOAuth($request, $googleService, true);
    }

    /**
     * Do google oauth validation and create or sign in user.
     *
     * @param GoogleOAuthRequest $request
     * @param GoogleOAuthService $googleService
     * @param boolean $createIfMissing
     * @param string $missingUserMessage
     * @return JsonResponse
     */
    private function handleGoogleOAuth(
        GoogleOAuthRequest $request,
        GoogleOAuthService $googleService,
        bool $createIfMissing,
        string $missingUserMessage = 'User not found.'
    ): JsonResponse {
        try {
            $validatedData = $request->validated();

            // Verify Google token and get user info
            $googleUser = $googleService->verifyGoogleToken($validatedData['access_token']);

            if (!$googleUser) {
                return response()->json(['message' => 'Invalid Google access token.'], 401);
            }

            // Verify that the Google ID matches
            if ($googleUser['id'] !== $validatedData['google_id']) {
                return response()->json(['message' => 'Google ID mismatch.'], 401);
            }

            // Sync or create user depending on flow
            $user = $googleService->syncUser($googleUser, $createIfMissing, $validatedData);

            if (!$user) {
                return response()->json(['message' => $missingUserMessage], 404);
            }

            // Generate access token
            $accessToken = $googleService->generateAccessToken($user);

            return response()->json([
                'access_token' => $accessToken,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'avatar' => $user->avatar,
                    'provider' => $user->provider,
                ]
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors()
            ], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'An error occurred while processing Google authentication.'], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/auth/logout",
     *     summary="Logout user",
     *     tags={"Auth"},
     *     security={{"sanctum": {}}},
     *     @OA\Response(
     *         response=200,
     *         description="Successfully logged out",
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string", example="Successfully logged out.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=401,
     *         description="Unauthenticated"
     *     )
     * )
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            
            if ($user) {
                // Revoke all tokens for the user
                $user->tokens()->delete();
            }

            return response()->json(['message' => 'Successfully logged out.']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'An error occurred while logging out.'], 500);
        }
    }
}
