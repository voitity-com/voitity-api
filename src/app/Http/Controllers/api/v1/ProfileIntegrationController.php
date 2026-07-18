<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use App\Models\User;
use App\Services\Integrations\InstagramIntegrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProfileIntegrationController extends Controller
{
    public function index(Request $request, Profile $profile): JsonResponse
    {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $integrations = $profile->integrations()
            ->withCount(['media', 'media as selected_media_count' => fn ($query) => $query->where('selected', true)])
            ->get();

        return response()->json([
            'message' => 'Profile integrations retrieved successfully.',
            'data' => [
                'integrations' => $integrations
                    ->map(fn (ProfileIntegration $integration) => $this->integrationToArray($integration))
                    ->values()
                    ->all(),
            ],
        ]);
    }

    public function instagramConnectUrl(
        Request $request,
        Profile $profile,
        InstagramIntegrationService $instagram
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        try {
            return response()->json([
                'message' => 'Instagram connection URL created successfully.',
                'data' => [
                    'url' => $instagram->connectUrl($profile, $request->user()),
                    'oauth' => $this->instagramOAuthDiagnostics(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Unable to create Instagram connection URL.', [
                'profile_id' => $profile->id,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function instagramCallback(Request $request, InstagramIntegrationService $instagram): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        $error = (string) $request->query('error', '');
        $adminBaseUrl = (string) config('instagram.admin_redirect_url', 'http://localhost:3000');

        if ($error !== '') {
            return redirect()->away($adminBaseUrl.'/dashboard/profiles?instagram=denied');
        }

        try {
            $integration = $instagram->handleCallback($code, $state);
            $synced = false;

            try {
                $instagram->sync($integration);
                $synced = true;
            } catch (\Throwable $e) {
                Log::warning('Instagram connected but initial sync failed.', [
                    'integration_id' => $integration->id,
                    'profile_id' => $integration->profile_id,
                    'message' => $e->getMessage(),
                ]);
            }

            return redirect()->away(
                $adminBaseUrl.'/dashboard/profiles/'.$integration->profile_id.'/integrations?provider=instagram&connected=1&synced='.($synced ? '1' : '0')
            );
        } catch (\Throwable $e) {
            Log::warning('Instagram OAuth callback failed.', [
                'message' => $e->getMessage(),
            ]);

            return redirect()->away($adminBaseUrl.'/dashboard/profiles?instagram=error');
        }
    }

    public function instagramSync(
        Request $request,
        Profile $profile,
        InstagramIntegrationService $instagram
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $integration = $this->instagramIntegration($profile);

        if (! $integration) {
            return response()->json(['message' => 'Instagram is not connected.'], 404);
        }

        try {
            $result = $instagram->sync($integration);

            return response()->json([
                'message' => 'Instagram media synced successfully.',
                'data' => [
                    'integration' => $this->integrationToArray($result['integration']),
                    'synced_count' => $result['synced_count'],
                ],
            ]);
        } catch (\Throwable $e) {
            $integration->forceFill([
                'status' => ProfileIntegration::STATUS_ERROR,
                'metadata' => [
                    ...($integration->metadata ?? []),
                    'last_error' => $e->getMessage(),
                    'last_error_at' => now()->toIso8601String(),
                ],
            ])->save();

            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    public function instagramMedia(Request $request, Profile $profile): JsonResponse
    {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $integration = $this->instagramIntegration($profile);

        if (! $integration) {
            return response()->json([
                'message' => 'Instagram is not connected.',
                'data' => [
                    'integration' => null,
                    'media' => [],
                    'oauth' => $this->instagramOAuthDiagnostics(),
                    'selection_limit' => $this->selectionLimit(),
                ],
            ]);
        }

        $media = $integration->media()
            ->orderByDesc('taken_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'message' => 'Instagram media retrieved successfully.',
            'data' => [
                'integration' => $this->integrationToArray($integration->loadCount([
                    'media',
                    'media as selected_media_count' => fn ($query) => $query->where('selected', true),
                ])),
                'media' => $media->map(fn (ProfileIntegrationMedia $media) => $this->mediaToArray($media))->all(),
                'oauth' => $this->instagramOAuthDiagnostics(),
                'selection_limit' => $this->selectionLimit(),
            ],
        ]);
    }

    public function instagramUpdateMediaSelection(
        Request $request,
        Profile $profile,
        InstagramIntegrationService $instagram
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $integration = $this->instagramIntegration($profile);

        if (! $integration) {
            return response()->json(['message' => 'Instagram is not connected.'], 404);
        }

        $validated = $request->validate([
            'media' => ['required', 'array'],
            'media.*.id' => ['required', 'integer'],
            'media.*.selected' => ['nullable', 'boolean'],
            'media.*.observation' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $integration = $instagram->updateSelection($integration->load('media'), $validated['media']);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['media' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'message' => 'Instagram media selection updated successfully.',
            'data' => [
                'integration' => $this->integrationToArray($integration->loadCount([
                    'media',
                    'media as selected_media_count' => fn ($query) => $query->where('selected', true),
                ])),
                'media' => $integration->media()
                    ->orderByDesc('taken_at')
                    ->orderByDesc('id')
                    ->get()
                    ->map(fn (ProfileIntegrationMedia $media) => $this->mediaToArray($media))
                    ->all(),
                'oauth' => $this->instagramOAuthDiagnostics(),
                'selection_limit' => $this->selectionLimit(),
            ],
        ]);
    }

    public function instagramDisconnect(Request $request, Profile $profile): JsonResponse
    {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $this->instagramIntegration($profile)?->delete();

        return response()->json([
            'message' => 'Instagram disconnected successfully.',
        ]);
    }

    private function authorizeProfile(Request $request, Profile $profile): ?JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ((int) $profile->user_id !== (int) $user->id) {
            return response()->json(['message' => 'Profile not found.'], 404);
        }

        return null;
    }

    private function instagramIntegration(Profile $profile): ?ProfileIntegration
    {
        return $profile->integrations()
            ->where('provider', ProfileIntegration::PROVIDER_INSTAGRAM)
            ->first();
    }

    private function integrationToArray(ProfileIntegration $integration): array
    {
        return [
            'id' => $integration->id,
            'provider' => $integration->provider,
            'provider_user_id' => $integration->provider_user_id,
            'username' => $integration->username,
            'status' => $integration->status,
            'expires_at' => $integration->expires_at?->toIso8601String(),
            'last_synced_at' => $integration->last_synced_at?->toIso8601String(),
            'media_count' => $integration->media_count ?? $integration->media()->count(),
            'selected_media_count' => $integration->selected_media_count
                ?? $integration->media()->where('selected', true)->count(),
            'metadata' => $integration->metadata,
        ];
    }

    private function mediaToArray(ProfileIntegrationMedia $media): array
    {
        return [
            'id' => $media->id,
            'provider_media_id' => $media->provider_media_id,
            'media_type' => $media->media_type,
            'media_url' => $media->media_url,
            'thumbnail_url' => $media->thumbnail_url,
            'permalink' => $media->permalink,
            'caption' => $media->caption,
            'observation' => filled($media->observation) ? $media->observation : $media->caption,
            'selected' => $media->selected,
            'taken_at' => $media->taken_at?->toIso8601String(),
        ];
    }

    private function selectionLimit(): int
    {
        return max(1, (int) config('instagram.selection_limit', 10));
    }

    /**
     * @return array<string, mixed>
     */
    private function instagramOAuthDiagnostics(): array
    {
        $redirectUri = (string) config('instagram.redirect_uri');
        $host = strtolower((string) parse_url($redirectUri, PHP_URL_HOST));

        return [
            'redirect_uri' => $redirectUri,
            'redirect_host' => $host,
            'uses_local_redirect' => in_array($host, ['localhost', '127.0.0.1', '::1'], true),
        ];
    }
}
