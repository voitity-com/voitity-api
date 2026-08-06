<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\Subscriptions\SubscriptionPlanCapabilityService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Integrations\StoreOtherMediaRequest;
use App\Http\Requests\Integrations\UpdateOtherMediaRequest;
use App\Http\Requests\Integrations\UpdateOtherMediaSelectionRequest;
use App\Http\Responses\Integrations\ProfileIntegrationMediaResponse;
use App\Models\Profile;
use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use App\Models\User;
use App\Services\Features\FeatureService;
use App\Services\Integrations\IntegrationDestinationCatalog;
use App\Services\Integrations\OtherIntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ProfileOtherIntegrationController extends Controller
{
    public function __construct(private readonly SubscriptionPlanCapabilityService $capabilities) {}

    public function destinations(Request $request, IntegrationDestinationCatalog $catalog): JsonResponse
    {
        $locale = $catalog->locale($request->query('locale'));

        return response()->json([
            'message' => 'Integration destinations retrieved successfully.',
            'data' => [
                'destinations' => $catalog->all($locale),
                'locale' => $locale,
            ],
        ]);
    }

    public function index(
        Request $request,
        Profile $profile,
        FeatureService $features,
        IntegrationDestinationCatalog $catalog,
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        if ($response = $this->ensureEnabled($profile, $features)) {
            return $response;
        }

        $locale = $catalog->locale($request->query('locale', $profile->locale));
        $integration = $this->integration($profile);

        return response()->json([
            'message' => 'Other integration media retrieved successfully.',
            'data' => [
                'integration' => $integration ? $this->integrationToArray($integration) : null,
                'media' => $integration
                    ? $integration->media()
                        ->orderByDesc('taken_at')
                        ->orderByDesc('id')
                        ->get()
                        ->map(fn (ProfileIntegrationMedia $media): array => $this->mediaToArray($media, $locale))
                        ->all()
                    : [],
                'selection_limit' => $this->selectionLimit($profile),
            ],
        ]);
    }

    public function store(
        StoreOtherMediaRequest $request,
        Profile $profile,
        OtherIntegrationService $service,
        FeatureService $features,
        IntegrationDestinationCatalog $catalog,
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        if ($response = $this->ensureEnabled($profile, $features)) {
            return $response;
        }

        try {
            $media = $service->upload(
                $profile,
                $request->user(),
                $request->file('file'),
                $request->validated(),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['file' => [$e->getMessage()]],
            ], 422);
        }

        $locale = $catalog->locale($request->query('locale', $profile->locale));

        return response()->json([
            'message' => 'Other integration media uploaded successfully.',
            'data' => [
                'integration' => $this->integrationToArray($media->integration),
                'media' => $this->mediaToArray($media, $locale),
                'selection_limit' => $this->selectionLimit($profile),
            ],
        ], 201);
    }

    public function update(
        UpdateOtherMediaRequest $request,
        Profile $profile,
        ProfileIntegrationMedia $media,
        OtherIntegrationService $service,
        FeatureService $features,
        IntegrationDestinationCatalog $catalog,
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        if ($response = $this->ensureEnabled($profile, $features)) {
            return $response;
        }

        $integration = $this->integration($profile);

        if (! $integration) {
            return response()->json(['message' => 'Other integration was not found.'], 404);
        }

        try {
            $media = $service->update($integration, $media, $request->validated(), $request->user());
        } catch (InvalidArgumentException $e) {
            $status = str_contains(mb_strtolower($e->getMessage()), 'not found') ? 404 : 422;

            return response()->json(['message' => $e->getMessage()], $status);
        }

        $locale = $catalog->locale($request->query('locale', $profile->locale));

        return response()->json([
            'message' => 'Other integration media updated successfully.',
            'data' => ['media' => $this->mediaToArray($media, $locale)],
        ]);
    }

    public function updateSelection(
        UpdateOtherMediaSelectionRequest $request,
        Profile $profile,
        OtherIntegrationService $service,
        FeatureService $features,
        IntegrationDestinationCatalog $catalog,
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        if ($response = $this->ensureEnabled($profile, $features)) {
            return $response;
        }

        $integration = $this->integration($profile);

        if (! $integration) {
            return response()->json(['message' => 'Other integration was not found.'], 404);
        }

        try {
            $integration = $service->updateSelection(
                $integration,
                $request->validated('media'),
                $request->user(),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['media' => [$e->getMessage()]],
            ], 422);
        }

        $locale = $catalog->locale($request->query('locale', $profile->locale));

        return response()->json([
            'message' => 'Other integration media selection updated successfully.',
            'data' => [
                'integration' => $this->integrationToArray($integration),
                'media' => $integration->media
                    ->sortByDesc('taken_at')
                    ->map(fn (ProfileIntegrationMedia $media): array => $this->mediaToArray($media, $locale))
                    ->values()
                    ->all(),
                'selection_limit' => $this->selectionLimit($profile),
            ],
        ]);
    }

    public function destroyMedia(
        Request $request,
        Profile $profile,
        ProfileIntegrationMedia $media,
        OtherIntegrationService $service,
        FeatureService $features,
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        if ($response = $this->ensureEnabled($profile, $features)) {
            return $response;
        }

        $integration = $this->integration($profile);

        if (! $integration) {
            return response()->json(['message' => 'Other integration was not found.'], 404);
        }

        try {
            $service->deleteMedia($integration, $media, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json(['message' => 'Other integration media deleted successfully.']);
    }

    public function destroy(
        Request $request,
        Profile $profile,
        OtherIntegrationService $service,
        FeatureService $features,
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        if ($response = $this->ensureEnabled($profile, $features)) {
            return $response;
        }

        $integration = $this->integration($profile);

        if ($integration) {
            $service->disconnect($integration, $request->user());
        }

        return response()->json(['message' => 'Other integration disconnected successfully.']);
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

    private function ensureEnabled(Profile $profile, FeatureService $features): ?JsonResponse
    {
        if ($features->isProfileIntegrationEnabled($profile, ProfileIntegration::PROVIDER_OTHER)) {
            return null;
        }

        return response()->json(['message' => 'Other is not enabled for this profile.'], 403);
    }

    private function integration(Profile $profile): ?ProfileIntegration
    {
        return $profile->integrations()
            ->where('provider', ProfileIntegration::PROVIDER_OTHER)
            ->withCount([
                'media',
                'media as selected_media_count' => fn ($query) => $query->where('selected', true),
            ])
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function integrationToArray(ProfileIntegration $integration): array
    {
        return [
            'id' => $integration->id,
            'provider' => $integration->provider,
            'provider_user_id' => $integration->provider_user_id,
            'username' => $integration->username,
            'status' => $integration->status,
            'expires_at' => $integration->expires_at?->toIso8601String(),
            'refresh_expires_at' => $integration->refresh_expires_at?->toIso8601String(),
            'last_synced_at' => $integration->last_synced_at?->toIso8601String(),
            'media_count' => $integration->media_count ?? $integration->media()->count(),
            'selected_media_count' => $integration->selected_media_count
                ?? $integration->media()->where('selected', true)->count(),
            'metadata' => $integration->metadata,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mediaToArray(ProfileIntegrationMedia $media, string $locale): array
    {
        return (new ProfileIntegrationMediaResponse($media, $locale))->toArray();
    }

    private function selectionLimit(Profile $profile): int
    {
        return $this->capabilities->selectedMediaPerProfile($profile, ProfileIntegration::PROVIDER_OTHER);
    }
}
