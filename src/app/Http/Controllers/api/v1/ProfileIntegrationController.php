<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use App\Models\User;
use App\Services\Integrations\InstagramIntegrationService;
use App\Services\Integrations\OnlyFansIntegrationService;
use App\Services\Integrations\TikTokIntegrationService;
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

    public function tiktokConnectUrl(
        Request $request,
        Profile $profile,
        TikTokIntegrationService $tiktok
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        try {
            return response()->json([
                'message' => 'TikTok connection URL created successfully.',
                'data' => [
                    'url' => $tiktok->connectUrl($profile, $request->user()),
                    'oauth' => $this->tiktokOAuthDiagnostics(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Unable to create TikTok connection URL.', [
                'profile_id' => $profile->id,
                'user_id' => $request->user()?->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function tiktokCallback(Request $request, TikTokIntegrationService $tiktok): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        $error = (string) $request->query('error', '');
        $adminBaseUrl = (string) config('tiktok.admin_redirect_url', 'http://localhost:3000');

        if ($error !== '') {
            return redirect()->away($adminBaseUrl.'/dashboard/profiles?tiktok=denied');
        }

        try {
            $integration = $tiktok->handleCallback($code, $state);
            $synced = false;

            try {
                $tiktok->sync($integration);
                $synced = true;
            } catch (\Throwable $e) {
                Log::warning('TikTok connected but initial sync failed.', [
                    'integration_id' => $integration->id,
                    'profile_id' => $integration->profile_id,
                    'message' => $e->getMessage(),
                ]);
            }

            return redirect()->away(
                $adminBaseUrl.'/dashboard/profiles/'.$integration->profile_id.'/integrations?provider=tiktok&connected=1&synced='.($synced ? '1' : '0')
            );
        } catch (\Throwable $e) {
            Log::warning('TikTok OAuth callback failed.', [
                'message' => $e->getMessage(),
            ]);

            return redirect()->away($adminBaseUrl.'/dashboard/profiles?tiktok=error');
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

    public function tiktokSync(
        Request $request,
        Profile $profile,
        TikTokIntegrationService $tiktok
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $integration = $this->tiktokIntegration($profile);

        if (! $integration) {
            return response()->json(['message' => 'TikTok is not connected.'], 404);
        }

        try {
            $result = $tiktok->sync($integration);

            return response()->json([
                'message' => 'TikTok media synced successfully.',
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
                    'selection_limit' => $this->selectionLimit(ProfileIntegration::PROVIDER_INSTAGRAM),
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
                'selection_limit' => $this->selectionLimit(ProfileIntegration::PROVIDER_INSTAGRAM),
            ],
        ]);
    }

    public function tiktokMedia(Request $request, Profile $profile): JsonResponse
    {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $integration = $this->tiktokIntegration($profile);

        if (! $integration) {
            return response()->json([
                'message' => 'TikTok is not connected.',
                'data' => [
                    'integration' => null,
                    'media' => [],
                    'oauth' => $this->tiktokOAuthDiagnostics(),
                    'selection_limit' => $this->selectionLimit(ProfileIntegration::PROVIDER_TIKTOK),
                ],
            ]);
        }

        $media = $integration->media()
            ->orderByDesc('taken_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'message' => 'TikTok media retrieved successfully.',
            'data' => [
                'integration' => $this->integrationToArray($integration->loadCount([
                    'media',
                    'media as selected_media_count' => fn ($query) => $query->where('selected', true),
                ])),
                'media' => $media->map(fn (ProfileIntegrationMedia $media) => $this->mediaToArray($media))->all(),
                'oauth' => $this->tiktokOAuthDiagnostics(),
                'selection_limit' => $this->selectionLimit(ProfileIntegration::PROVIDER_TIKTOK),
            ],
        ]);
    }

    public function onlyFansConnect(
        Request $request,
        Profile $profile,
        OnlyFansIntegrationService $onlyFans
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:150', 'regex:/^@?[A-Za-z0-9._-]+$/'],
            'profile_url' => ['required', 'url:http,https', 'max:2048'],
            'rights_confirmed' => ['required', 'accepted'],
            'adult_content_confirmed' => ['required', 'accepted'],
        ]);

        try {
            $integration = $onlyFans->connect($profile, $request->user(), $validated);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['profile_url' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'message' => 'OnlyFans integration saved successfully.',
            'data' => [
                'integration' => $this->integrationToArray($integration->loadCount([
                    'media',
                    'media as selected_media_count' => fn ($query) => $query->where('selected', true),
                ])),
            ],
        ]);
    }

    public function onlyFansMedia(Request $request, Profile $profile): JsonResponse
    {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $integration = $this->onlyFansIntegration($profile);

        if (! $integration) {
            return response()->json([
                'message' => 'OnlyFans is not connected.',
                'data' => [
                    'integration' => null,
                    'media' => [],
                    'selection_limit' => $this->selectionLimit(ProfileIntegration::PROVIDER_ONLYFANS),
                ],
            ]);
        }

        return response()->json([
            'message' => 'OnlyFans media retrieved successfully.',
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
                'selection_limit' => $this->selectionLimit(ProfileIntegration::PROVIDER_ONLYFANS),
            ],
        ]);
    }

    public function onlyFansUploadMedia(
        Request $request,
        Profile $profile,
        OnlyFansIntegrationService $onlyFans
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $integration = $this->onlyFansIntegration($profile);

        if (! $integration) {
            return response()->json(['message' => 'OnlyFans is not connected.'], 404);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime,video/webm', 'max:102400'],
            'caption' => ['nullable', 'string', 'max:2000'],
            'observation' => ['nullable', 'string', 'max:1000'],
            'selected' => ['nullable', 'boolean'],
            'rights_confirmed' => ['required', 'accepted'],
        ]);

        try {
            $media = $onlyFans->upload(
                $integration,
                $validated['file'],
                $validated['caption'] ?? null,
                $validated['observation'] ?? null,
                (bool) ($validated['selected'] ?? false)
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['file' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'message' => 'OnlyFans media uploaded successfully.',
            'data' => [
                'media' => $this->mediaToArray($media),
            ],
        ], 201);
    }

    public function onlyFansUpdateMediaSelection(
        Request $request,
        Profile $profile,
        OnlyFansIntegrationService $onlyFans
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $integration = $this->onlyFansIntegration($profile);

        if (! $integration) {
            return response()->json(['message' => 'OnlyFans is not connected.'], 404);
        }

        $validated = $request->validate([
            'media' => ['required', 'array'],
            'media.*.id' => ['required', 'integer'],
            'media.*.selected' => ['nullable', 'boolean'],
            'media.*.observation' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $integration = $onlyFans->updateSelection($integration, $validated['media']);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['media' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'message' => 'OnlyFans media selection updated successfully.',
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
                'selection_limit' => $this->selectionLimit(ProfileIntegration::PROVIDER_ONLYFANS),
            ],
        ]);
    }

    public function onlyFansDeleteMedia(
        Request $request,
        Profile $profile,
        ProfileIntegrationMedia $media,
        OnlyFansIntegrationService $onlyFans
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $integration = $this->onlyFansIntegration($profile);

        if (! $integration) {
            return response()->json(['message' => 'OnlyFans is not connected.'], 404);
        }

        try {
            $onlyFans->deleteMedia($integration, $media);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'message' => 'OnlyFans media deleted successfully.',
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
                'selection_limit' => $this->selectionLimit(ProfileIntegration::PROVIDER_INSTAGRAM),
            ],
        ]);
    }

    public function tiktokUpdateMediaSelection(
        Request $request,
        Profile $profile,
        TikTokIntegrationService $tiktok
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $integration = $this->tiktokIntegration($profile);

        if (! $integration) {
            return response()->json(['message' => 'TikTok is not connected.'], 404);
        }

        $validated = $request->validate([
            'media' => ['required', 'array'],
            'media.*.id' => ['required', 'integer'],
            'media.*.selected' => ['nullable', 'boolean'],
            'media.*.observation' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $integration = $tiktok->updateSelection($integration->load('media'), $validated['media']);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['media' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'message' => 'TikTok media selection updated successfully.',
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
                'oauth' => $this->tiktokOAuthDiagnostics(),
                'selection_limit' => $this->selectionLimit(ProfileIntegration::PROVIDER_TIKTOK),
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

    public function tiktokDisconnect(
        Request $request,
        Profile $profile,
        TikTokIntegrationService $tiktok
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $integration = $this->tiktokIntegration($profile);

        if ($integration) {
            $tiktok->revoke($integration);
            $integration->delete();
        }

        return response()->json([
            'message' => 'TikTok disconnected successfully.',
        ]);
    }

    public function onlyFansDisconnect(
        Request $request,
        Profile $profile,
        OnlyFansIntegrationService $onlyFans
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $integration = $this->onlyFansIntegration($profile);

        if ($integration) {
            $onlyFans->disconnect($integration);
        }

        return response()->json([
            'message' => 'OnlyFans disconnected successfully.',
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

    private function tiktokIntegration(Profile $profile): ?ProfileIntegration
    {
        return $profile->integrations()
            ->where('provider', ProfileIntegration::PROVIDER_TIKTOK)
            ->first();
    }

    private function onlyFansIntegration(Profile $profile): ?ProfileIntegration
    {
        return $profile->integrations()
            ->where('provider', ProfileIntegration::PROVIDER_ONLYFANS)
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
            'refresh_expires_at' => $integration->refresh_expires_at?->toIso8601String(),
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
            'provider' => $media->provider,
            'provider_media_id' => $media->provider_media_id,
            'media_type' => $media->media_type,
            'media_url' => $media->media_url,
            'thumbnail_url' => $media->thumbnail_url,
            'permalink' => $media->permalink,
            'caption' => $media->caption,
            'observation' => filled($media->observation) ? $media->observation : $media->caption,
            'age_restricted' => $media->age_restricted,
            'selected' => $media->selected,
            'taken_at' => $media->taken_at?->toIso8601String(),
        ];
    }

    private function selectionLimit(string $provider): int
    {
        $key = match ($provider) {
            ProfileIntegration::PROVIDER_TIKTOK => 'tiktok',
            ProfileIntegration::PROVIDER_ONLYFANS => 'onlyfans',
            default => 'instagram',
        };

        return max(1, (int) config("{$key}.selection_limit", 10));
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

    /**
     * @return array<string, mixed>
     */
    private function tiktokOAuthDiagnostics(): array
    {
        $redirectUri = (string) config('tiktok.redirect_uri');
        $host = strtolower((string) parse_url($redirectUri, PHP_URL_HOST));

        return [
            'redirect_uri' => $redirectUri,
            'redirect_host' => $host,
            'uses_local_redirect' => in_array($host, ['localhost', '127.0.0.1', '::1'], true),
        ];
    }
}
