<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Features\FeatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminFeatureController extends Controller
{
    public function index(Request $request, FeatureService $features): JsonResponse
    {
        if ($response = $this->authorizeAdmin($request)) {
            return $response;
        }

        return response()->json([
            'message' => 'Feature flags retrieved successfully.',
            'data' => [
                'features' => $features->globalFeatureRows(),
            ],
        ]);
    }

    public function update(Request $request, FeatureService $features): JsonResponse
    {
        if ($response = $this->authorizeAdmin($request)) {
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
            'message' => 'Feature flags updated successfully.',
            'data' => [
                'features' => $features->updateGlobalFeatures($this->flattenFeatureInput($validated['features'])),
            ],
        ]);
    }

    private function authorizeAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->role !== 'admin') {
            return response()->json(['message' => 'Feature flags are only available to admins.'], 403);
        }

        return null;
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
