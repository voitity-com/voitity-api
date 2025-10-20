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
     * Create or update user from Google OAuth data.
     *
     * @param array $googleUser
     * @return User
     */
    public function createOrUpdateUser(array $googleUser): User
    {
        $email = $googleUser['email'];
        $googleId = $googleUser['id'];

        // First, try to find user by Google ID
        $user = User::where('google_id', $googleId)->first();

        if ($user) {
            // Update existing user with Google ID
            $user->update([
                'name' => $googleUser['name'] ?? $user->name,
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
                'name' => $googleUser['name'] ?? $user->name,
                'avatar' => $googleUser['picture'] ?? $user->avatar,
                'provider' => 'google',
                'google_verified_at' => now(),
                'email_verified_at' => $googleUser['verified_email'] ? now() : $user->email_verified_at,
            ]);
            return $user;
        }

        // Create new user
        return User::create([
            'name' => $googleUser['name'],
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
