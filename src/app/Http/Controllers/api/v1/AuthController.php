<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\EmailSignUpRequest;
use App\Http\Requests\Auth\GoogleOAuthRequest;
use App\Http\Requests\Auth\LoginHistoryRequest;
use App\Http\Requests\Auth\PasswordChangeRequest;
use App\Http\Requests\Auth\PasswordForgotRequest;
use App\Http\Requests\Auth\PasswordResetRequest;
use App\Http\Requests\Auth\PasswordResetValidateRequest;
use App\Mail\Auth\PasswordChanged;
use App\Mail\Auth\PasswordResetLink;
use App\Mail\Auth\VerifyEmailAddress;
use App\Mail\Auth\WelcomeEmail;
use App\Models\AuthLoginEvent;
use App\Models\User;
use App\Services\EmailVerificationResult;
use App\Services\EmailVerificationService;
use App\Services\GoogleOAuthService;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\PasswordResetResult;
use App\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class AuthController extends Controller
{
    /**
     * @OA\Post(
     *     path="/api/auth/get-token",
     *     summary="Get access token",
     *     tags={"Auth"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"email","password"},
     *
     *             @OA\Property(property="email", type="string", example="voitity@gmail.com"),
     *             @OA\Property(property="password", type="string", example="qwerty123")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful login, returns access token",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="access_token", type="string")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=403,
     *         description="Invalid credentials",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Your email or password are incorrect.")
     *         )
     *     )
     * )
     * Returns an access token.
     */
    public function getToken(Request $request): JsonResponse
    {
        try {
            $login = $request->validate([
                'email' => 'required|string',
                'password' => 'required|string',
            ]);

            $guard = Auth::guard('web');

            if (! $guard->attempt($login)) {
                return response()->json(['message' => 'Your email or password are incorrect.'], 403);
            }

            $user = $guard->user();

            if (! ($user instanceof User)) {
                return response()->json(['message' => 'User not found.'], 404);
            }

            if (! $user->email_verified_at) {
                $guard->logout();

                return response()->json(['message' => 'Please verify your email address before signing in.'], 403);
            }

            if ($user->role === 'forgotten') {
                $user->role = $user->active ? 'user' : 'inactive';
                $user->save();
            }

            $token = $user->createToken('token-name', $user->getRoleAbilities());
            $this->recordLoginEvent($request, $user, 'credential');

            return response()->json(
                [
                    'access_token' => $token->plainTextToken,
                ],
                200
            );

        } catch (ValidationException $e) {
            return response()->json(['message' => 'Your email or password are incorrect.'], 403);
        } catch (\Throwable $e) {
            Log::error('Token authentication failed unexpectedly.', [
                'email' => $request->input('email'),
                'exception' => $e,
            ]);

            return response()->json(['message' => 'An error occurred while processing your request.'], 500);
        }
    }

    /**
     * @OA\Post(
     *     path="/api/auth/sign-up",
     *     summary="Sign up with email and password",
     *     tags={"Auth"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"name","email","password","password_confirmation"},
     *
     *             @OA\Property(property="name", type="string", example="Abel Moreno"),
     *             @OA\Property(property="first_name", type="string", nullable=true, example="Abel"),
     *             @OA\Property(property="last_name", type="string", nullable=true, example="Moreno"),
     *             @OA\Property(property="email", type="string", example="abel@example.com"),
     *             @OA\Property(property="password", type="string", example="Test12345!"),
     *             @OA\Property(property="password_confirmation", type="string", example="Test12345!"),
     *             @OA\Property(property="checkout_intent", type="object", nullable=true,
     *                 @OA\Property(property="intent", type="string", enum={"checkout","trial"}),
     *                 @OA\Property(property="plan", type="string", example="starter"),
     *                 @OA\Property(property="cycle", type="string", enum={"month","year"}),
     *                 @OA\Property(property="locale", type="string", enum={"en","es"}),
     *                 @OA\Property(property="landingVariant", type="string", example="entrenadores"),
     *                 @OA\Property(property="attribution", type="object")
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=201,
     *         description="Successful email sign up. User must verify email before signing in.",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="email_verification_required", type="boolean"),
     *             @OA\Property(property="user", type="object",
     *                 @OA\Property(property="id", type="integer"),
     *                 @OA\Property(property="name", type="string"),
     *                 @OA\Property(property="email", type="string"),
     *                 @OA\Property(property="first_name", type="string", nullable=true),
     *                 @OA\Property(property="last_name", type="string", nullable=true),
     *                 @OA\Property(property="avatar", type="string", nullable=true),
     *                 @OA\Property(property="provider", type="string"),
     *                 @OA\Property(property="checkout_intent", type="object", nullable=true)
     *             )
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=422,
     *         description="Validation error"
     *     )
     * )
     */
    public function signUp(EmailSignUpRequest $request, EmailVerificationService $emailVerificationService): JsonResponse
    {
        $validated = $request->validated();
        [$firstName, $lastName] = $this->nameParts(
            $validated['name'],
            $validated['first_name'] ?? null,
            $validated['last_name'] ?? null,
        );

        $user = User::create([
            'role' => 'user',
            'name' => $validated['name'],
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $validated['email'],
            'locale' => $validated['locale'],
            'password' => $validated['password'],
            'provider' => 'email',
            'pending_checkout_intent' => $validated['checkout_intent'] ?? null,
        ]);

        $verificationUrl = $emailVerificationService->createVerificationUrl($user);

        Log::info(
            'Email verification link generated.',
            [
                'email' => $user->email,
                'user_id' => $user->id,
                'verification_url' => app()->environment(['local', 'testing']) ? $verificationUrl : null,
            ]
        );

        if (! $this->sendAuthMail($user, new VerifyEmailAddress($user, $verificationUrl), 'email_verification')) {
            $user->delete();

            return response()->json([
                'message' => $this->authMailDeliveryFailedMessage($validated['locale'], 'verification'),
                'email_delivery_failed' => true,
                'email_verification_required' => true,
            ], 503);
        }

        app(NotificationDispatcher::class)->sendInApp($user, 'account_email_confirmation', [
            'email' => $user->email,
        ]);

        return response()->json([
            'message' => 'We sent a verification link to your email address.',
            'email_verification_required' => true,
            'user' => $this->authUserPayload($user),
        ], 201);
    }

    public function forgotPassword(
        PasswordForgotRequest $request,
        PasswordResetService $passwordResetService
    ): JsonResponse {
        $validated = $request->validated();
        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            return response()->json([
                'message' => $this->passwordResetRequestedMessage($validated['locale']),
            ]);
        }

        if ($this->usesGoogleAuthentication($user)) {
            app(NotificationDispatcher::class)->sendInApp($user, 'password_recovery_requested_for_google_account');

            return response()->json([
                'message' => $this->googlePasswordResetMessage($user),
                'provider' => 'google',
            ], 409);
        }

        $resetUrl = $passwordResetService->createResetUrl($user);

        Log::info(
            'Password reset link generated.',
            [
                'email' => $user->email,
                'user_id' => $user->id,
                'reset_url' => app()->environment(['local', 'testing']) ? $resetUrl : null,
            ]
        );

        if (! $this->sendAuthMail($user, new PasswordResetLink($user, $resetUrl), 'password_reset')) {
            $passwordResetService->consume($user);

            return response()->json([
                'message' => $this->authMailDeliveryFailedMessage($user->locale ?: $validated['locale'], 'password_reset'),
                'email_delivery_failed' => true,
            ], 503);
        }

        return response()->json([
            'message' => $this->passwordResetRequestedMessage($user->locale ?: $validated['locale']),
        ]);
    }

    public function resetPassword(
        PasswordResetRequest $request,
        PasswordResetService $passwordResetService
    ): JsonResponse {
        $validated = $request->validated();
        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            return response()->json([
                'message' => 'This password reset link is invalid.',
                'status' => PasswordResetResult::Invalid->value,
            ], 422);
        }

        if ($this->usesGoogleAuthentication($user)) {
            return response()->json([
                'message' => $this->googlePasswordResetMessage($user),
                'provider' => 'google',
            ], 409);
        }

        $result = $passwordResetService->verify($user, $validated['token']);

        if ($result !== PasswordResetResult::Valid) {
            return response()->json([
                'message' => $this->passwordResetResultMessage($result, $user->locale ?: 'en'),
                'status' => $result->value,
            ], $this->passwordResetStatusCode($result));
        }

        $user->forceFill([
            'password' => $validated['password'],
            'email_verified_at' => $user->email_verified_at ?: now(),
        ])->save();
        $user->refresh();

        $passwordResetService->consume($user);
        $user->tokens()->delete();

        $this->sendAuthMail($user, new PasswordChanged($user), 'password_changed');
        app(NotificationDispatcher::class)->sendInApp($user, 'password_changed_confirmation');

        Log::info('Password reset completed.', [
            'email' => $user->email,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => $this->passwordChangedMessage($user->locale ?: 'en'),
            'status' => 'changed',
        ]);
    }

    public function changePassword(PasswordChangeRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($this->usesGoogleAuthentication($user)) {
            return response()->json([
                'message' => $this->googlePasswordResetMessage($user),
                'provider' => 'google',
            ], 409);
        }

        $validated = $request->validated();

        if (! Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => $this->currentPasswordIncorrectMessage($user->locale ?: 'en'),
            ], 422);
        }

        $user->forceFill(['password' => $validated['password']])->save();
        $user->refresh();
        $this->revokeOtherTokens($user);

        $this->sendAuthMail($user, new PasswordChanged($user), 'password_changed');
        app(NotificationDispatcher::class)->sendInApp($user, 'password_changed_from_active_session');

        Log::info('Password changed from active session.', [
            'email' => $user->email,
            'user_id' => $user->id,
        ]);

        return response()->json([
            'message' => $this->passwordChangedMessage($user->locale ?: 'en'),
            'status' => 'changed',
        ]);
    }

    public function loginHistory(LoginHistoryRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! ($user instanceof User)) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        $perPage = (int) $request->validated('per_page', 10);
        $events = $user->loginEvents()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'message' => 'Login history retrieved successfully.',
            'data' => [
                'events' => collect($events->items())->map(fn (AuthLoginEvent $event): array => [
                    'id' => $event->id,
                    'type' => $event->type,
                    'ip_address' => $event->ip_address,
                    'user_agent' => $event->user_agent,
                    'created_at' => $event->created_at?->toJSON(),
                ])->values()->all(),
                'pagination' => [
                    'current_page' => $events->currentPage(),
                    'last_page' => $events->lastPage(),
                    'per_page' => $events->perPage(),
                    'total' => $events->total(),
                ],
            ],
        ]);
    }

    public function validatePasswordResetLink(
        PasswordResetValidateRequest $request,
        PasswordResetService $passwordResetService
    ): JsonResponse {
        $validated = $request->validated();
        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            return response()->json([
                'message' => $this->passwordResetResultMessage(PasswordResetResult::Invalid, $validated['locale']),
                'status' => PasswordResetResult::Invalid->value,
            ], 422);
        }

        if ($this->usesGoogleAuthentication($user)) {
            return response()->json([
                'message' => $this->googlePasswordResetMessage($user),
                'provider' => 'google',
            ], 409);
        }

        $result = $passwordResetService->verify($user, $validated['token']);

        return response()->json([
            'message' => $this->passwordResetResultMessage($result, $user->locale ?: $validated['locale']),
            'status' => $result->value,
        ], $this->passwordResetStatusCode($result));
    }

    public function verifyEmail(
        Request $request,
        User $user,
        EmailVerificationService $emailVerificationService
    ): JsonResponse|RedirectResponse {
        $result = $emailVerificationService->verify($user, $request->query('token'));

        if ($result === EmailVerificationResult::Verified) {
            $user->refresh();

            $this->sendAuthMail($user, new WelcomeEmail($user), 'welcome');
            app(NotificationDispatcher::class)->sendInApp($user, 'welcome_after_email_verification');

            Log::info('Email verified successfully.', [
                'email' => $user->email,
                'user_id' => $user->id,
            ]);
        }

        if (! $request->boolean('redirect') && $request->wantsJson()) {
            return response()->json([
                'message' => $this->verificationMessage($result),
                'status' => $result->value,
            ], $this->verificationStatusCode($result));
        }

        return $this->verificationRedirect($result, $user);
    }

    /**
     * @OA\Post(
     *     path="/api/auth/google/sign-in",
     *     summary="Sign in with Google OAuth",
     *     tags={"Auth"},
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"google_id","email","name","first_name","last_name","access_token"},
     *
     *             @OA\Property(property="google_id", type="string", example="123456789012345678901"),
     *             @OA\Property(property="email", type="string", example="user@gmail.com"),
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="first_name", type="string", example="John"),
     *             @OA\Property(property="last_name", type="string", example="Doe"),
     *             @OA\Property(property="avatar", type="string", nullable=true, example="https://lh3.googleusercontent.com/a/photo.jpg"),
     *             @OA\Property(property="access_token", type="string", example="ya29.a0AfH6SMC...")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful Google sign in",
     *
     *         @OA\JsonContent(
     *
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
     *
     *     @OA\Response(
     *         response=401,
     *         description="Invalid Google token",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Invalid Google access token.")
     *         )
     *     ),
     *
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
     *
     *     @OA\RequestBody(
     *         required=true,
     *
     *         @OA\JsonContent(
     *             required={"google_id","email","name","first_name","last_name","access_token"},
     *
     *             @OA\Property(property="google_id", type="string", example="123456789012345678901"),
     *             @OA\Property(property="email", type="string", example="user@gmail.com"),
     *             @OA\Property(property="name", type="string", example="John Doe"),
     *             @OA\Property(property="first_name", type="string", example="John"),
     *             @OA\Property(property="last_name", type="string", example="Doe"),
     *             @OA\Property(property="avatar", type="string", nullable=true, example="https://lh3.googleusercontent.com/a/photo.jpg"),
     *             @OA\Property(property="access_token", type="string", example="ya29.a0AfH6SMC...")
     *         )
     *     ),
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successful Google sign up",
     *
     *         @OA\JsonContent(
     *
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
     *
     *     @OA\Response(
     *         response=401,
     *         description="Invalid Google token",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Invalid Google access token.")
     *         )
     *     ),
     *
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

            if (! $googleUser) {
                return response()->json(['message' => 'Invalid Google access token.'], 401);
            }

            // Verify that the Google ID matches
            if ($googleUser['id'] !== $validatedData['google_id']) {
                return response()->json(['message' => 'Google ID mismatch.'], 401);
            }

            // Sync or create user depending on flow
            $user = $googleService->syncUser($googleUser, $createIfMissing, $validatedData);

            if (! $user) {
                return response()->json(['message' => $missingUserMessage], 404);
            }

            if (! $user->email_verified_at) {
                return response()->json(['message' => 'Please verify your email address before signing in.'], 403);
            }

            // Generate access token
            $accessToken = $googleService->generateAccessToken($user);
            $this->recordLoginEvent($request, $user, 'google');

            return response()->json([
                'access_token' => $accessToken,
                'user' => $this->authUserPayload($user),
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'An error occurred while processing Google authentication.'], 500);
        }
    }

    /**
     * @return array{id: int, name: string|null, email: string|null, first_name: string|null, last_name: string|null, avatar: string|null, provider: string|null, role: string|null}
     */
    private function authUserPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'avatar' => $user->avatar,
            'provider' => $user->provider,
            'role' => $user->role,
            'locale' => $user->locale,
            'email_verified_at' => $user->email_verified_at?->toJSON(),
            'checkout_intent' => $user->pending_checkout_intent,
        ];
    }

    private function verificationRedirect(EmailVerificationResult $result, User $user): RedirectResponse
    {
        $redirectUrl = (string) config('email-verification.redirect_url');
        $separator = str_contains($redirectUrl, '?') ? '&' : '?';

        return redirect()->away($redirectUrl.$separator.http_build_query([
            'verification' => $result->value,
            'locale' => $user->locale ?: 'en',
        ]));
    }

    private function verificationMessage(EmailVerificationResult $result): string
    {
        return match ($result) {
            EmailVerificationResult::Verified => 'Your email address has been verified.',
            EmailVerificationResult::AlreadyVerified => 'Your email address is already verified.',
            EmailVerificationResult::Expired => 'This verification link has expired.',
            EmailVerificationResult::Invalid => 'This verification link is invalid.',
        };
    }

    private function verificationStatusCode(EmailVerificationResult $result): int
    {
        return match ($result) {
            EmailVerificationResult::Verified, EmailVerificationResult::AlreadyVerified => 200,
            EmailVerificationResult::Expired => 410,
            EmailVerificationResult::Invalid => 422,
        };
    }

    private function usesGoogleAuthentication(User $user): bool
    {
        return $user->provider === 'google' || filled($user->google_id);
    }

    private function passwordResetRequestedMessage(string $locale): string
    {
        if ($locale === 'es') {
            return 'Si el correo pertenece a una cuenta con contraseña, enviamos un enlace para cambiarla.';
        }

        return 'If the email belongs to a password account, we sent a link to change it.';
    }

    private function googlePasswordResetMessage(User $user): string
    {
        if ($user->locale === 'es') {
            return 'Esta cuenta usa inicio de sesión con Google. Ingresa con el botón de Google.';
        }

        return 'This account uses Google sign-in. Use the Google button to continue.';
    }

    private function passwordResetResultMessage(PasswordResetResult $result, string $locale): string
    {
        if ($locale === 'es') {
            return match ($result) {
                PasswordResetResult::Expired => 'Este enlace para cambiar contraseña expiró.',
                PasswordResetResult::Invalid => 'Este enlace para cambiar contraseña no es válido.',
                PasswordResetResult::Valid => 'El enlace es válido.',
            };
        }

        return match ($result) {
            PasswordResetResult::Expired => 'This password reset link has expired.',
            PasswordResetResult::Invalid => 'This password reset link is invalid.',
            PasswordResetResult::Valid => 'This password reset link is valid.',
        };
    }

    private function passwordResetStatusCode(PasswordResetResult $result): int
    {
        return match ($result) {
            PasswordResetResult::Valid => 200,
            PasswordResetResult::Expired => 410,
            PasswordResetResult::Invalid => 422,
        };
    }

    private function passwordChangedMessage(string $locale): string
    {
        if ($locale === 'es') {
            return 'Tu contraseña fue actualizada correctamente.';
        }

        return 'Your password was updated successfully.';
    }

    private function currentPasswordIncorrectMessage(string $locale): string
    {
        if ($locale === 'es') {
            return 'La contraseña actual es incorrecta.';
        }

        return 'Current password is incorrect.';
    }

    private function authMailDeliveryFailedMessage(string $locale, string $purpose): string
    {
        if ($locale === 'es') {
            return match ($purpose) {
                'password_reset' => 'No pudimos enviar el correo para cambiar tu contraseña. Intenta de nuevo más tarde.',
                default => 'No pudimos enviar el correo de verificación. Intenta de nuevo más tarde.',
            };
        }

        return match ($purpose) {
            'password_reset' => 'We could not send the password reset email. Please try again later.',
            default => 'We could not send the verification email. Please try again later.',
        };
    }

    private function sendAuthMail(User $user, Mailable $mailable, string $type): bool
    {
        try {
            Mail::to($user->email)->send($mailable);

            return true;
        } catch (TransportExceptionInterface $exception) {
            Log::error('Auth email delivery failed.', [
                'email' => $user->email,
                'user_id' => $user->id,
                'type' => $type,
                'exception' => $exception,
            ]);

            return false;
        }
    }

    private function recordLoginEvent(Request $request, User $user, string $type): void
    {
        AuthLoginEvent::create([
            'user_id' => $user->id,
            'type' => $type,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1000),
        ]);
    }

    private function revokeOtherTokens(User $user): void
    {
        $currentToken = $user->currentAccessToken();
        $currentTokenId = is_object($currentToken) && method_exists($currentToken, 'getKey')
            ? $currentToken->getKey()
            : null;

        $user->tokens()
            ->when($currentTokenId, fn ($query) => $query->whereKeyNot($currentTokenId))
            ->delete();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function nameParts(string $name, ?string $firstName, ?string $lastName): array
    {
        if ($firstName || $lastName) {
            return [
                $firstName ?: $name,
                $lastName ?: '',
            ];
        }

        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = array_shift($parts) ?: $name;

        return [$first, implode(' ', $parts)];
    }

    /**
     * @OA\Post(
     *     path="/api/auth/logout",
     *     summary="Logout user",
     *     tags={"Auth"},
     *     security={{"sanctum": {}}},
     *
     *     @OA\Response(
     *         response=200,
     *         description="Successfully logged out",
     *
     *         @OA\JsonContent(
     *
     *             @OA\Property(property="message", type="string", example="Successfully logged out.")
     *         )
     *     ),
     *
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
