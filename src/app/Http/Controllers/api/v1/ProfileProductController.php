<?php

namespace App\Http\Controllers\api\v1;

use App\Enums\ProfileProductStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\BulkProfileProductDestinationRequest;
use App\Http\Requests\Products\BulkProfileProductStatusRequest;
use App\Http\Requests\Products\StoreProfileProductRequest;
use App\Http\Requests\Products\UpdateProfileProductRequest;
use App\Http\Requests\Products\UpdateProfileProductsSettingRequest;
use App\Http\Responses\Products\ProfileProductResponse;
use App\Models\Profile;
use App\Models\ProfileProduct;
use App\Models\User;
use App\Services\Features\FeatureService;
use App\Services\Products\ProfileProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ProfileProductController extends Controller
{
    public function index(
        Request $request,
        Profile $profile,
        ProfileProductService $service,
        FeatureService $features
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        if ($response = $this->ensureProductsEnabled($profile, $features)) {
            return $response;
        }

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'search' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', 'in:draft,published'],
            'destination_type' => ['nullable', 'in:external_url,whatsapp,telegram'],
        ]);
        $products = $profile->products()
            ->with('profile')
            ->when($validated['search'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when(
                $validated['destination_type'] ?? null,
                fn ($query, string $type) => $query->where('destination_type', $type)
            )
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'message' => 'Profile products retrieved successfully.',
            'data' => [
                'products_enabled' => (bool) $profile->products_enabled,
                'recommendation_guidance' => $profile->product_recommendation_guidance,
                'max_products' => $service->maxProducts($profile),
                'available_slots' => max(0, $service->maxProducts($profile) - $profile->products()->count()),
                'products' => collect($products->items())
                    ->map(fn (ProfileProduct $product): array => (new ProfileProductResponse($product))->toArray())
                    ->all(),
                'pagination' => [
                    'current_page' => $products->currentPage(),
                    'last_page' => $products->lastPage(),
                    'per_page' => $products->perPage(),
                    'total' => $products->total(),
                ],
            ],
        ]);
    }

    public function store(
        StoreProfileProductRequest $request,
        Profile $profile,
        ProfileProductService $service,
        FeatureService $features
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        if ($response = $this->ensureProductsEnabled($profile, $features)) {
            return $response;
        }

        try {
            $product = $service->create($profile, $request->user(), $request->validated(), $request->file('image'));

            return response()->json([
                'message' => 'Profile product created successfully.',
                'data' => (new ProfileProductResponse($product->load('profile')))->toArray(),
            ], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function update(
        UpdateProfileProductRequest $request,
        Profile $profile,
        ProfileProduct $product,
        ProfileProductService $service,
        FeatureService $features
    ): JsonResponse {
        if ($response = $this->authorizeProduct($request, $profile, $product)) {
            return $response;
        }

        if ($response = $this->ensureProductsEnabled($profile, $features)) {
            return $response;
        }

        try {
            $product = $service->update($product, $request->validated(), $request->file('image'));

            return response()->json([
                'message' => 'Profile product updated successfully.',
                'data' => (new ProfileProductResponse($product))->toArray(),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function destroy(
        Request $request,
        Profile $profile,
        ProfileProduct $product,
        ProfileProductService $service,
        FeatureService $features
    ): JsonResponse {
        if ($response = $this->authorizeProduct($request, $profile, $product)) {
            return $response;
        }

        if ($response = $this->ensureProductsEnabled($profile, $features)) {
            return $response;
        }

        $service->delete($product);

        return response()->json(['message' => 'Profile product deleted successfully.']);
    }

    public function settings(
        UpdateProfileProductsSettingRequest $request,
        Profile $profile,
        ProfileProductService $service,
        FeatureService $features
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        if ($response = $this->ensureProductsEnabled($profile, $features)) {
            return $response;
        }

        $profile = $service->updateSettings($profile, $request->validated());

        return response()->json([
            'message' => 'Profile product conversation setting updated successfully.',
            'data' => [
                'products_enabled' => (bool) $profile->products_enabled,
                'recommendation_guidance' => $profile->product_recommendation_guidance,
            ],
        ]);
    }

    public function bulkStatus(
        BulkProfileProductStatusRequest $request,
        Profile $profile,
        ProfileProductService $service,
        FeatureService $features
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        if ($response = $this->ensureProductsEnabled($profile, $features)) {
            return $response;
        }

        $updated = $service->bulkStatus(
            $profile,
            $request->validated('product_ids'),
            ProfileProductStatus::from($request->validated('status'))
        );

        return response()->json([
            'message' => 'Profile product statuses updated successfully.',
            'data' => ['updated' => $updated],
        ]);
    }

    public function bulkDestination(
        BulkProfileProductDestinationRequest $request,
        Profile $profile,
        ProfileProductService $service,
        FeatureService $features
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        if ($response = $this->ensureProductsEnabled($profile, $features)) {
            return $response;
        }

        try {
            $updated = $service->bulkDestination(
                $profile,
                $request->validated('product_ids'),
                $request->validated()
            );

            return response()->json([
                'message' => 'Profile product destinations updated successfully.',
                'data' => ['updated' => $updated],
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function authorizeProduct(Request $request, Profile $profile, ProfileProduct $product): ?JsonResponse
    {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        if ((int) $product->profile_id !== (int) $profile->id) {
            return response()->json(['message' => 'Profile product not found.'], 404);
        }

        return null;
    }

    private function ensureProductsEnabled(Profile $profile, FeatureService $features): ?JsonResponse
    {
        if ($features->isProfileFeatureEnabled($profile, FeatureService::PRODUCTS)) {
            return null;
        }

        return response()->json(['message' => 'Products are not enabled for this profile.'], 403);
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
}
