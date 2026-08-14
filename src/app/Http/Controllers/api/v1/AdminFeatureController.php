<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Features\FeatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
            'features.domains' => ['nullable', 'array'],
            'features.domains.custom' => ['nullable', 'boolean'],
            'features.integrations' => ['nullable', 'array'],
            'features.products' => ['nullable', 'boolean'],
            'features.integrations.instagram' => ['nullable', 'boolean'],
            'features.integrations.tiktok' => ['nullable', 'boolean'],
            'features.integrations.onlyfans' => ['nullable', 'boolean'],
            'features.integrations.other' => ['nullable', 'boolean'],
            'features.integrations.youtube' => ['nullable', 'boolean'],
        ]);

        $featureInput = $this->flattenFeatureInput($validated['features']);
        $rows = $features->updateGlobalFeatures($featureInput);

        Log::info('Global feature flags updated.', [
            'actor_user_id' => $request->user()?->id,
            'features' => $featureInput,
        ]);

        return response()->json([
            'message' => 'Feature flags updated successfully.',
            'data' => [
                'features' => $rows,
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

        foreach (($features['domains'] ?? []) as $domainFeature => $enabled) {
            $flattened["domains.{$domainFeature}"] = (bool) $enabled;
        }

        foreach (($features['integrations'] ?? []) as $provider => $enabled) {
            $flattened["integrations.{$provider}"] = (bool) $enabled;
        }

        return $flattened;
    }
}
