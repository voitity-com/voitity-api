<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleOAuthService
{
    /**
     * Google API endpoint to verify token and get user info.
     */
    private const GOOGLE_USER_INFO_URL = 'https://www.googleapis.com/oauth2/v2/userinfo';

    /**
     * Verify Google access token and get user information.
     *
     * @param string $accessToken
     * @return array|null
     */
    public function verifyGoogleToken(string $accessToken): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $accessToken,
            ])->get(self::GOOGLE_USER_INFO_URL);

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning('Google token verification failed', [
                'status' => $response->status(),
                'response' => $response->body()
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('Google token verification error', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Sync user data from Google OAuth payload.
     *
     * @param array $googleUser
     * @param bool $createIfMissing
     * @param array $payload
     * @return User|null
     */
    public function syncUser(array $googleUser, bool $createIfMissing = true, array $payload = []): ?User
    {
        $email = $googleUser['email'];
        $googleId = $googleUser['id'];
        $firstName = $payload['first_name'] ?? ($googleUser['given_name'] ?? null);
        $lastName = $payload['last_name'] ?? ($googleUser['family_name'] ?? null);
        $displayName = $payload['name'] ?? ($googleUser['name'] ?? trim(($firstName ?? '') . ' ' . ($lastName ?? '')));

        // First, try to find user by Google ID
        $user = User::where('google_id', $googleId)->first();

        if ($user) {
            // Update existing user with Google ID
            $user->update([
                'name' => $displayName ?: $user->name,
                'first_name' => $firstName ?? $user->first_name,
                'last_name' => $lastName ?? $user->last_name,
                'avatar' => $googleUser['picture'] ?? $user->avatar,
                'google_verified_at' => now(),
            ]);
            return $user;
        }

        // Try to find user by email
        $user = User::where('email', $email)->first();

        if ($user) {
            // Link existing user account with Google
            $user->update([
                'google_id' => $googleId,
                'name' => $displayName ?: $user->name,
                'first_name' => $firstName ?? $user->first_name,
                'last_name' => $lastName ?? $user->last_name,
                'avatar' => $googleUser['picture'] ?? $user->avatar,
                'provider' => 'google',
                'google_verified_at' => now(),
                'email_verified_at' => $googleUser['verified_email'] ? now() : $user->email_verified_at,
            ]);
            return $user;
        }

        if (!$createIfMissing) {
            return null;
        }

        // Create new user
        return User::create([
            'name' => $displayName ?: ($googleUser['name'] ?? ''),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'google_id' => $googleId,
            'avatar' => $googleUser['picture'] ?? null,
            'provider' => 'google',
            'role' => 'user',
            'password' => bcrypt(Str::random(32)), // Random password since they use Google OAuth
            'email_verified_at' => $googleUser['verified_email'] ? now() : null,
            'google_verified_at' => now(),
        ]);
    }

    /**
     * Generate access token for user.
     *
     * @param User $user
     * @return string
     */
    public function generateAccessToken(User $user): string
    {
        // Revoke existing tokens for security
        $user->tokens()->delete();

        // Create new token with user abilities
        $token = $user->createToken('google-oauth-token', $user->getRoleAbilities());

        return $token->plainTextToken;
    }
}
