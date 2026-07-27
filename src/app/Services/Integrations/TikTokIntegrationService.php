<?php

namespace App\Services\Integrations;

use App\Models\Profile;
use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use App\Models\User;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class TikTokIntegrationService
{
    private const STATE_CACHE_PREFIX = 'tiktok_oauth_state:';

    public function connectUrl(Profile $profile, User $user): string
    {
        $this->assertConfigured();
        $this->assertUserOwnsProfile($profile, $user);

        $state = Str::random(64);
        $codeVerifier = $this->usesPkce() ? Str::random(64) : null;
        Cache::put(
            $this->stateCacheKey($state),
            array_filter([
                'profile_id' => $profile->id,
                'user_id' => $user->id,
                'code_verifier' => $codeVerifier,
            ]),
            now()->addMinutes(max(1, (int) config('tiktok.oauth_state_ttl_minutes', 10)))
        );

        $query = [
            'client_key' => config('tiktok.client_key'),
            'redirect_uri' => config('tiktok.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(',', config('tiktok.scopes', ['user.info.basic', 'video.list'])),
            'state' => $state,
        ];

        if ($codeVerifier !== null) {
            $query['code_challenge'] = hash('sha256', $codeVerifier);
            $query['code_challenge_method'] = 'S256';
        }

        return rtrim((string) config('tiktok.auth_url'), '?').'?'.http_build_query($query);
    }

    public function handleCallback(string $code, string $state): ProfileIntegration
    {
        $statePayload = Cache::pull($this->stateCacheKey($state));

        if (! is_array($statePayload)) {
            throw new InvalidArgumentException('Invalid or expired TikTok OAuth state.');
        }

        $profile = Profile::find((int) ($statePayload['profile_id'] ?? 0));
        $user = User::find((int) ($statePayload['user_id'] ?? 0));

        if (! $profile || ! $user || (int) $profile->user_id !== (int) $user->id) {
            throw new InvalidArgumentException('Invalid TikTok OAuth profile.');
        }

        $codeVerifier = $this->scalarString($statePayload['code_verifier'] ?? null);
        $token = $this->exchangeCodeForToken($code, $codeVerifier);
        $accessToken = (string) ($token['access_token'] ?? '');

        if ($accessToken === '') {
            throw new RuntimeException('TikTok did not return an access token.');
        }

        $account = $this->fetchAccount($accessToken);

        return ProfileIntegration::updateOrCreate(
            [
                'profile_id' => $profile->id,
                'provider' => ProfileIntegration::PROVIDER_TIKTOK,
            ],
            [
                'user_id' => $user->id,
                'provider_user_id' => $this->scalarString($account['open_id'] ?? $token['open_id'] ?? null),
                'username' => $this->scalarString($account['display_name'] ?? null),
                'access_token' => $accessToken,
                'refresh_token' => $this->scalarString($token['refresh_token'] ?? null),
                'token_type' => $this->scalarString($token['token_type'] ?? 'Bearer'),
                'scopes' => $this->normalizeScopes($token['scope'] ?? config('tiktok.scopes', ['user.info.basic', 'video.list'])),
                'expires_at' => isset($token['expires_in']) ? now()->addSeconds((int) $token['expires_in']) : null,
                'refresh_expires_at' => isset($token['refresh_expires_in'])
                    ? now()->addSeconds((int) $token['refresh_expires_in'])
                    : null,
                'status' => ProfileIntegration::STATUS_CONNECTED,
                'metadata' => array_filter([
                    'account' => $account,
                    'token_open_id' => $token['open_id'] ?? null,
                ]),
            ]
        );
    }

    /**
     * @return array{integration: ProfileIntegration, synced_count: int}
     */
    public function sync(ProfileIntegration $integration): array
    {
        if ($integration->provider !== ProfileIntegration::PROVIDER_TIKTOK) {
            throw new InvalidArgumentException('Unsupported integration provider.');
        }

        $accessToken = $this->validAccessToken($integration);

        if ($accessToken === '') {
            throw new RuntimeException('TikTok access token is missing.');
        }

        $account = $this->fetchAccount($accessToken);
        $videos = $this->fetchVideos($accessToken);

        DB::transaction(function () use ($account, $integration, $videos): void {
            $integration->forceFill([
                'provider_user_id' => $this->scalarString($account['open_id'] ?? $integration->provider_user_id),
                'username' => $this->scalarString($account['display_name'] ?? $integration->username),
                'last_synced_at' => now(),
                'status' => ProfileIntegration::STATUS_CONNECTED,
                'metadata' => array_filter([
                    ...($integration->metadata ?? []),
                    'account' => $account,
                ]),
            ])->save();

            foreach ($videos as $item) {
                $providerMediaId = $this->scalarString($item['id'] ?? null);

                if (! $providerMediaId) {
                    continue;
                }

                $caption = $this->scalarString($item['title'] ?? null)
                    ?: $this->scalarString($item['video_description'] ?? null);
                $media = ProfileIntegrationMedia::firstOrNew(
                    [
                        'profile_integration_id' => $integration->id,
                        'provider_media_id' => $providerMediaId,
                    ]
                );

                $media->forceFill([
                    'profile_id' => $integration->profile_id,
                    'provider' => ProfileIntegration::PROVIDER_TIKTOK,
                    'media_type' => $this->mediaType($item),
                    'media_url' => $this->scalarString($item['share_url'] ?? $item['embed_link'] ?? null),
                    'thumbnail_url' => $this->scalarString($item['cover_image_url'] ?? null),
                    'permalink' => $this->scalarString($item['share_url'] ?? $item['embed_link'] ?? null),
                    'caption' => $caption,
                    'taken_at' => isset($item['create_time']) ? $this->parseUnixTimestamp($item['create_time']) : null,
                    'metadata' => $item,
                ]);

                if (filled($caption) && ! filled($media->observation)) {
                    $media->observation = $caption;
                }

                $media->save();
            }
        });

        return [
            'integration' => $integration->fresh(['media']),
            'synced_count' => count($videos),
        ];
    }

    /**
     * @param  array<int, array{id: int|string, selected?: bool, observation?: null|string}>  $items
     */
    public function updateSelection(ProfileIntegration $integration, array $items): ProfileIntegration
    {
        $selectedItems = collect($items)
            ->filter(fn (array $item): bool => (bool) ($item['selected'] ?? false))
            ->values();
        $selectionLimit = max(1, (int) config('tiktok.selection_limit', 10));

        if ($selectedItems->count() > $selectionLimit) {
            throw new InvalidArgumentException("You can select up to {$selectionLimit} TikTok items.");
        }

        $mediaIds = $integration->media()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $itemsById = collect($items)->keyBy(fn (array $item): int => (int) $item['id']);

        DB::transaction(function () use ($integration, $itemsById, $mediaIds): void {
            foreach ($mediaIds as $mediaId) {
                $item = $itemsById->get($mediaId);
                $media = ProfileIntegrationMedia::query()
                    ->where('profile_integration_id', $integration->id)
                    ->whereKey($mediaId)
                    ->first();

                if (! $media instanceof ProfileIntegrationMedia) {
                    continue;
                }

                $observation = null;

                if (is_array($item) && array_key_exists('observation', $item)) {
                    $candidate = trim((string) $item['observation']);
                    $observation = $candidate !== '' ? $candidate : null;
                }

                if ($observation === null && filled($media->caption)) {
                    $observation = $media->caption;
                }

                $media->forceFill([
                    'selected' => is_array($item) && (bool) ($item['selected'] ?? false),
                    'observation' => $observation,
                ])->save();
            }
        });

        return $integration->fresh(['media']);
    }

    public function revoke(ProfileIntegration $integration): void
    {
        $accessToken = (string) $integration->access_token;

        if ($accessToken === '') {
            return;
        }

        $url = (string) config('tiktok.revoke_url');

        try {
            Http::asForm()
                ->post($url, [
                    'client_key' => config('tiktok.client_key'),
                    'client_secret' => config('tiktok.client_secret'),
                    'token' => $accessToken,
                ])
                ->throw();
        } catch (RequestException $e) {
            Log::notice('TikTok token revoke failed.', $this->tiktokFailureContext('token revoke', $url, $e));
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function selectedMediaForPrompt(Profile $profile): array
    {
        return $profile->integrationMedia()
            ->where('provider', ProfileIntegration::PROVIDER_TIKTOK)
            ->where('selected', true)
            ->orderByDesc('taken_at')
            ->orderByDesc('id')
            ->limit(max(1, (int) config('tiktok.selection_limit', 10)))
            ->get()
            ->map(fn (ProfileIntegrationMedia $media): array => [
                'id' => $media->id,
                'provider' => 'TikTok',
                'provider_key' => ProfileIntegration::PROVIDER_TIKTOK,
                'provider_label' => 'TikTok',
                'source_type' => 'social_network',
                'media_type' => $media->media_type,
                'image_url' => $media->thumbnail_url ?: $media->media_url,
                'media_url' => $media->media_url,
                'thumbnail_url' => $media->thumbnail_url,
                'permalink' => $media->permalink,
                'caption' => $media->caption,
                'observation' => filled($media->observation) ? $media->observation : $media->caption,
                'taken_at' => $media->taken_at?->toDateString(),
            ])
            ->filter(fn (array $media): bool => filled($media['image_url'] ?? null) || filled($media['permalink'] ?? null))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function exchangeCodeForToken(string $code, ?string $codeVerifier = null): array
    {
        $url = (string) config('tiktok.token_url');
        $payload = array_filter([
            'client_key' => config('tiktok.client_key'),
            'client_secret' => config('tiktok.client_secret'),
            'code' => $code,
            'code_verifier' => $codeVerifier,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('tiktok.redirect_uri'),
        ], fn ($value): bool => $value !== null && $value !== '');

        try {
            $response = Http::asForm()
                ->post($url, $payload)
                ->throw()
                ->json();
        } catch (RequestException $e) {
            throw $this->tiktokRequestException('token exchange', $url, $e);
        }

        return $this->normalizeTokenPayload($response);
    }

    private function usesPkce(): bool
    {
        $configured = config('tiktok.pkce_enabled');

        if ($configured !== null) {
            return filter_var($configured, FILTER_VALIDATE_BOOL);
        }

        $host = strtolower((string) parse_url((string) config('tiktok.redirect_uri'), PHP_URL_HOST));

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function validAccessToken(ProfileIntegration $integration): string
    {
        $accessToken = (string) $integration->access_token;
        $refreshToken = (string) $integration->refresh_token;
        $leewayMinutes = max(0, (int) config('tiktok.refresh_leeway_minutes', 10));

        if (
            $accessToken !== ''
            && (
                ! $integration->expires_at
                || $integration->expires_at->greaterThan(now()->addMinutes($leewayMinutes))
            )
        ) {
            return $accessToken;
        }

        if ($refreshToken === '') {
            return $accessToken;
        }

        $token = $this->refreshAccessToken($refreshToken);
        $newAccessToken = (string) ($token['access_token'] ?? '');

        if ($newAccessToken === '') {
            throw new RuntimeException('TikTok did not return an access token during refresh.');
        }

        $integration->forceFill([
            'access_token' => $newAccessToken,
            'refresh_token' => $this->scalarString($token['refresh_token'] ?? null) ?: $refreshToken,
            'token_type' => $this->scalarString($token['token_type'] ?? $integration->token_type),
            'scopes' => $this->normalizeScopes($token['scope'] ?? $integration->scopes ?? []),
            'expires_at' => isset($token['expires_in']) ? now()->addSeconds((int) $token['expires_in']) : $integration->expires_at,
            'refresh_expires_at' => isset($token['refresh_expires_in'])
                ? now()->addSeconds((int) $token['refresh_expires_in'])
                : $integration->refresh_expires_at,
            'status' => ProfileIntegration::STATUS_CONNECTED,
        ])->save();

        return $newAccessToken;
    }

    /**
     * @return array<string, mixed>
     */
    private function refreshAccessToken(string $refreshToken): array
    {
        $url = (string) config('tiktok.token_url');

        try {
            $response = Http::asForm()
                ->post($url, [
                    'client_key' => config('tiktok.client_key'),
                    'client_secret' => config('tiktok.client_secret'),
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ])
                ->throw()
                ->json();
        } catch (RequestException $e) {
            throw $this->tiktokRequestException('token refresh', $url, $e);
        }

        return $this->normalizeTokenPayload($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchAccount(string $accessToken): array
    {
        $url = $this->apiUrl('/v2/user/info/');

        try {
            $response = Http::withToken($accessToken)
                ->get($url, [
                    'fields' => 'open_id,union_id,avatar_url,display_name',
                ])
                ->throw()
                ->json();
        } catch (RequestException $e) {
            throw $this->tiktokRequestException('account lookup', $url, $e);
        }

        $this->assertTikTokOk($response, 'account lookup');

        $user = data_get($response, 'data.user', []);

        return is_array($user) ? $user : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchVideos(string $accessToken): array
    {
        $limit = max(1, (int) config('tiktok.media_limit', 100));
        $url = $this->apiUrl('/v2/video/list/');
        $fields = 'id,title,video_description,cover_image_url,share_url,embed_link,create_time,duration,height,width';
        $videos = [];
        $cursor = null;

        do {
            $body = ['max_count' => min(20, max(1, $limit - count($videos)))];

            if ($cursor !== null) {
                $body['cursor'] = $cursor;
            }

            try {
                $response = Http::withToken($accessToken)
                    ->post($url.'?'.http_build_query(['fields' => $fields]), $body)
                    ->throw()
                    ->json();
            } catch (RequestException $e) {
                throw $this->tiktokRequestException('video lookup', $url, $e);
            }

            $this->assertTikTokOk($response, 'video lookup');

            $videos = [
                ...$videos,
                ...array_values(array_filter(
                    Arr::wrap(data_get($response, 'data.videos', [])),
                    fn ($item): bool => is_array($item)
                )),
            ];
            $hasMore = (bool) data_get($response, 'data.has_more', false);
            $nextCursor = data_get($response, 'data.cursor');

            if (! $hasMore || $nextCursor === null || count($videos) >= $limit || $nextCursor === $cursor) {
                break;
            }

            $cursor = $nextCursor;
        } while (count($videos) < $limit);

        return array_slice($videos, 0, $limit);
    }

    private function apiUrl(string $path): string
    {
        return rtrim((string) config('tiktok.api_base_url'), '/').'/'.ltrim($path, '/');
    }

    private function tiktokRequestException(string $operation, string $url, RequestException $e): RuntimeException
    {
        $context = $this->tiktokFailureContext($operation, $url, $e);

        Log::warning('TikTok API request failed.', $context);

        return new RuntimeException(
            "TikTok {$operation} failed: ".$context['api_message'],
            0,
            $e
        );
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function assertTikTokOk(?array $payload, string $operation): void
    {
        $errorCode = data_get($payload, 'error.code');

        if ($errorCode === null || $errorCode === 'ok') {
            return;
        }

        $message = data_get($payload, 'error.message') ?: data_get($payload, 'error.log_id') ?: 'unknown error';

        throw new RuntimeException("TikTok {$operation} failed: {$message}");
    }

    /**
     * @return array<string, mixed>
     */
    private function tiktokFailureContext(string $operation, string $url, RequestException $e): array
    {
        $response = $e->response;
        $payload = $response?->json();
        $message = data_get($payload, 'error.message')
            ?: data_get($payload, 'message')
            ?: Str::limit((string) ($response?->body() ?: $e->getMessage()), 300);

        return [
            'operation' => $operation,
            'url' => $url,
            'status' => $response?->status(),
            'api_message' => $message,
            'api_error_code' => data_get($payload, 'error.code') ?: data_get($payload, 'error_code'),
            'api_log_id' => data_get($payload, 'error.log_id') ?: data_get($payload, 'log_id'),
        ];
    }

    private function stateCacheKey(string $state): string
    {
        return self::STATE_CACHE_PREFIX.hash('sha256', $state);
    }

    private function assertConfigured(): void
    {
        foreach (['client_key', 'client_secret', 'redirect_uri'] as $key) {
            if (! filled(config("tiktok.{$key}"))) {
                throw new RuntimeException("TikTok {$key} is not configured.");
            }
        }
    }

    private function assertUserOwnsProfile(Profile $profile, User $user): void
    {
        if ((int) $profile->user_id !== (int) $user->id) {
            throw new InvalidArgumentException('Profile not found.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeTokenPayload(?array $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $data = data_get($payload, 'data');

        if (is_array($data) && array_key_exists('access_token', $data)) {
            return $data;
        }

        return $payload;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeScopes(mixed $scopes): array
    {
        if (is_string($scopes)) {
            $scopes = explode(',', $scopes);
        }

        if (! is_array($scopes)) {
            return [];
        }

        return collect($scopes)
            ->map(fn ($scope): string => trim((string) $scope))
            ->filter()
            ->values()
            ->all();
    }

    private function scalarString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mediaType(array $item): string
    {
        if (isset($item['duration']) && is_numeric($item['duration']) && (float) $item['duration'] <= 0) {
            return 'IMAGE';
        }

        $coverImageUrl = strtolower((string) ($item['cover_image_url'] ?? ''));

        return str_contains($coverImageUrl, 'photomode') ? 'IMAGE' : 'VIDEO';
    }

    private function parseUnixTimestamp(mixed $value): ?\DateTimeInterface
    {
        if (! is_numeric($value)) {
            return null;
        }

        try {
            return (new \DateTimeImmutable)->setTimestamp((int) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
