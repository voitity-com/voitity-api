<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use App\Services\Features\FeatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileFeatureController extends Controller
{
    public function index(Request $request, Profile $profile, FeatureService $features): JsonResponse
    {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        return response()->json([
            'message' => 'Profile feature settings retrieved successfully.',
            'data' => [
                'features' => $features->profileFeatureRows($profile),
            ],
        ]);
    }

    public function update(Request $request, Profile $profile, FeatureService $features): JsonResponse
    {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $validated = $request->validate([
            'features' => ['required', 'array'],
            'features.integrations' => ['nullable', 'array'],
            'features.products' => ['nullable', 'boolean'],
            'features.integrations.instagram' => ['nullable', 'boolean'],
            'features.integrations.tiktok' => ['nullable', 'boolean'],
            'features.integrations.onlyfans' => ['nullable', 'boolean'],
            'features.integrations.youtube' => ['nullable', 'boolean'],
        ]);

        return response()->json([
            'message' => 'Profile feature settings updated successfully.',
            'data' => [
                'features' => $features->updateProfileFeatures($profile, $this->flattenFeatureInput($validated['features'])),
            ],
        ]);
    }

    private function authorizeProfile(Request $request, Profile $profile): ?JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->role === 'admin' || (int) $profile->user_id === (int) $user->id) {
            return null;
        }

        return response()->json(['message' => 'Profile not found.'], 404);
    }

    /**
     * @param  array<string, mixed>  $features
     * @return array<string, bool>
     */
    private function flattenFeatureInput(array $features): array
    {
        $flattened = [];

        if (array_key_exists('products', $features)) {
            $flattened['products'] = (bool) $features['products'];
        }

        foreach (($features['integrations'] ?? []) as $provider => $enabled) {
            $flattened["integrations.{$provider}"] = (bool) $enabled;
        }

        return $flattened;
    }
}
