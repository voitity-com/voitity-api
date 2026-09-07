<?php

namespace App\Services\Integrations;

use App\Classes\Subscriptions\SubscriptionPlanCapabilityService;
use App\Models\Profile;
use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use App\Models\User;
use App\Services\ProfileKnowledge\ProfileIntegrationKnowledgeLifecycle;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class InstagramIntegrationService
{
    private const STATE_CACHE_PREFIX = 'instagram_oauth_state:';

    public function __construct(
        private readonly SubscriptionPlanCapabilityService $capabilities,
        private readonly ProfileIntegrationKnowledgeLifecycle $knowledgeLifecycle,
    ) {}

    public function connectUrl(Profile $profile, User $user): string
    {
        $this->assertConfigured();
        $this->assertUserOwnsProfile($profile, $user);

        $state = Str::random(64);
        Cache::put(
            $this->stateCacheKey($state),
            ['profile_id' => $profile->id, 'user_id' => $user->id],
            now()->addMinutes(max(1, (int) config('instagram.oauth_state_ttl_minutes', 10)))
        );

        Log::info('Instagram OAuth connection initiated.', [
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'state_hash' => hash('sha256', $state),
        ]);

        $query = [
            'client_id' => config('instagram.client_id'),
            'enable_fb_login' => config('instagram.enable_fb_login', false) ? '1' : '0',
            'force_reauth' => config('instagram.force_reauth', true) ? '1' : '0',
            'redirect_uri' => config('instagram.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(',', config('instagram.scopes', ['instagram_business_basic'])),
            'state' => $state,
        ];

        return rtrim((string) config('instagram.auth_url'), '?').'?'.http_build_query($query);
    }

    public function handleCallback(string $code, string $state): ProfileIntegration
    {
        $statePayload = Cache::pull($this->stateCacheKey($state));

        if (! is_array($statePayload)) {
            throw new InvalidArgumentException('Invalid or expired Instagram OAuth state.');
        }

        $profile = Profile::find((int) ($statePayload['profile_id'] ?? 0));
        $user = User::find((int) ($statePayload['user_id'] ?? 0));

        if (! $profile || ! $user || (int) $profile->user_id !== (int) $user->id) {
            throw new InvalidArgumentException('Invalid Instagram OAuth profile.');
        }

        $shortToken = $this->unwrapDataObject($this->exchangeCodeForToken($code));
        $token = $this->exchangeLongLivedToken($shortToken['access_token'] ?? null) ?: $shortToken;
        $accessToken = (string) ($token['access_token'] ?? $shortToken['access_token'] ?? '');

        if ($accessToken === '') {
            throw new RuntimeException('Instagram did not return an access token.');
        }

        $shortTokenUserId = $this->scalarString($shortToken['user_id'] ?? $shortToken['id'] ?? null);
        $account = $this->fetchAccount($accessToken, $shortTokenUserId);
        $providerUserId = $this->scalarString(
            $account['user_id'] ?? $account['id'] ?? $shortTokenUserId
        );

        if ($providerUserId === null) {
            throw new RuntimeException('Instagram did not return a user ID.');
        }

        $grantedScopes = $this->grantedScopes($shortToken);
        $expiresIn = isset($token['expires_in'])
            ? (int) $token['expires_in']
            : (int) config('instagram.short_lived_token_ttl_seconds', 3600);

        return ProfileIntegration::updateOrCreate(
            [
                'profile_id' => $profile->id,
                'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            ],
            [
                'user_id' => $user->id,
                'provider_user_id' => $providerUserId,
                'username' => $this->scalarString($account['username'] ?? null),
                'access_token' => $accessToken,
                'token_type' => $this->scalarString($token['token_type'] ?? 'bearer'),
                'scopes' => $grantedScopes ?: config('instagram.scopes', ['instagram_business_basic']),
                'expires_at' => now()->addSeconds(max(1, $expiresIn)),
                'status' => ProfileIntegration::STATUS_CONNECTED,
                'metadata' => array_filter([
                    'account' => $account,
                    'short_token_user_id' => $shortTokenUserId,
                ]),
            ]
        );
    }

    /**
     * @return array{profile_id: int|null, user_id: int|null}
     */
    public function callbackContext(string $state): array
    {
        $payload = Cache::get($this->stateCacheKey($state));

        return [
            'profile_id' => is_array($payload) && isset($payload['profile_id']) ? (int) $payload['profile_id'] : null,
            'user_id' => is_array($payload) && isset($payload['user_id']) ? (int) $payload['user_id'] : null,
        ];
    }

    /**
     * @return array{integration: ProfileIntegration, synced_count: int}
     */
    public function sync(ProfileIntegration $integration): array
    {
        if ($integration->provider !== ProfileIntegration::PROVIDER_INSTAGRAM) {
            throw new InvalidArgumentException('Unsupported integration provider.');
        }

        $accessToken = (string) $integration->access_token;

        if ($accessToken === '') {
            throw new RuntimeException('Instagram access token is missing.');
        }

        $account = $this->fetchAccount($accessToken, $this->scalarString($integration->provider_user_id));
        $providerUserId = $this->scalarString(
            $account['user_id'] ?? $account['id'] ?? $integration->provider_user_id
        );

        if ($providerUserId === null) {
            throw new RuntimeException('Instagram user ID is missing.');
        }

        $media = $this->fetchMedia($accessToken, $providerUserId);

        DB::transaction(function () use ($account, $integration, $media, $providerUserId): void {
            $integration->forceFill([
                'provider_user_id' => $providerUserId,
                'username' => $this->scalarString($account['username'] ?? $integration->username),
                'last_synced_at' => now(),
                'status' => ProfileIntegration::STATUS_CONNECTED,
                'metadata' => array_filter([
                    ...($integration->metadata ?? []),
                    'account' => $account,
                ]),
            ])->save();

            foreach ($media as $item) {
                $providerMediaId = $this->scalarString($item['id'] ?? null);

                if (! $providerMediaId) {
                    continue;
                }

                $caption = $this->scalarString($item['caption'] ?? null);
                $media = ProfileIntegrationMedia::firstOrNew(
                    [
                        'profile_integration_id' => $integration->id,
                        'provider_media_id' => $providerMediaId,
                    ]
                );

                $media->forceFill([
                    'profile_id' => $integration->profile_id,
                    'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
                    'media_type' => $this->scalarString($item['media_type'] ?? null),
                    'media_url' => $this->scalarString($item['media_url'] ?? null),
                    'thumbnail_url' => $this->scalarString($item['thumbnail_url'] ?? null),
                    'permalink' => $this->scalarString($item['permalink'] ?? null),
                    'caption' => $caption,
                    'taken_at' => isset($item['timestamp']) ? $this->parseTimestamp((string) $item['timestamp']) : null,
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
            'synced_count' => count($media),
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
        $selectionLimit = $this->capabilities->selectedMediaPerProfile(
            $integration->profile,
            ProfileIntegration::PROVIDER_INSTAGRAM
        );

        if ($selectedItems->count() > $selectionLimit) {
            throw new InvalidArgumentException("You can select up to {$selectionLimit} Instagram items.");
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

        $this->knowledgeLifecycle->selectionChanged($integration);

        return $integration->fresh(['media']);
    }

    public function disconnect(ProfileIntegration $integration): void
    {
        $mediaIds = $integration->media()->pluck('id')->all();
        $profileId = (int) $integration->profile_id;
        $integration->deleteQuietly();
        $this->knowledgeLifecycle->forgetMedia($profileId, $mediaIds, ProfileIntegration::PROVIDER_INSTAGRAM);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function selectedMediaForPrompt(Profile $profile): array
    {
        return $profile->integrationMedia()
            ->where('provider', ProfileIntegration::PROVIDER_INSTAGRAM)
            ->where('selected', true)
            ->orderByDesc('taken_at')
            ->orderByDesc('id')
            ->limit($this->capabilities->selectedMediaPerProfile(
                $profile,
                ProfileIntegration::PROVIDER_INSTAGRAM
            ))
            ->get()
            ->map(fn (ProfileIntegrationMedia $media): array => [
                'id' => $media->id,
                'provider' => 'Instagram',
                'provider_key' => ProfileIntegration::PROVIDER_INSTAGRAM,
                'provider_label' => 'Instagram',
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
    private function exchangeCodeForToken(string $code): array
    {
        $url = (string) config('instagram.token_url');

        try {
            return Http::asForm()
                ->post($url, [
                    'client_id' => config('instagram.client_id'),
                    'client_secret' => config('instagram.client_secret'),
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => config('instagram.redirect_uri'),
                    'code' => $code,
                ])
                ->throw()
                ->json();
        } catch (RequestException $e) {
            throw $this->instagramRequestException('token exchange', $url, $e);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function exchangeLongLivedToken(?string $shortAccessToken): ?array
    {
        if (! $shortAccessToken) {
            return null;
        }

        $url = (string) config('instagram.long_lived_token_url');

        try {
            return $this->unwrapDataObject(Http::get($url, [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => config('instagram.client_secret'),
                'access_token' => $shortAccessToken,
            ])->throw()->json());
        } catch (RequestException $e) {
            $this->logInstagramRequestFailure('long-lived token exchange', $url, $e);

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchAccount(string $accessToken, ?string $providerUserId = null): array
    {
        $paths = ['/me'];

        if ($providerUserId !== null) {
            $paths[] = '/'.rawurlencode($providerUserId);
        }

        foreach (array_unique($paths) as $index => $path) {
            $url = $this->graphUrl($path);

            try {
                $account = $this->unwrapDataObject(Http::get($url, [
                    'access_token' => $accessToken,
                    'fields' => 'user_id,username,name,account_type,profile_picture_url,followers_count,follows_count,media_count',
                ])->throw()->json());

                if ($account !== []) {
                    return $account;
                }
            } catch (RequestException $e) {
                if ($index === array_key_last($paths)) {
                    throw $this->instagramRequestException('account lookup', $url, $e);
                }

                $this->logInstagramRequestFailure('account lookup', $url, $e);
            }
        }

        throw new RuntimeException('Instagram returned an empty account response.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchMedia(string $accessToken, string $providerUserId): array
    {
        $url = $this->graphUrl('/'.rawurlencode($providerUserId).'/media');

        try {
            $response = Http::get($url, [
                'access_token' => $accessToken,
                'fields' => 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp',
                'limit' => max(1, (int) config('instagram.media_limit', 100)),
            ])->throw()->json();
        } catch (RequestException $e) {
            throw $this->instagramRequestException('media lookup', $url, $e);
        }

        return array_values(array_filter(
            Arr::wrap($response['data'] ?? []),
            fn ($item): bool => is_array($item)
        ));
    }

    private function graphUrl(string $path): string
    {
        $baseUrl = rtrim((string) config('instagram.graph_base_url'), '/');

        if (! preg_match('#/v\d+\.\d+$#', $baseUrl)) {
            $version = trim((string) config('instagram.graph_api_version', 'v25.0'), '/');
            $baseUrl .= '/'.$version;
        }

        return $baseUrl.'/'.ltrim($path, '/');
    }

    private function instagramRequestException(string $operation, string $url, RequestException $e): RuntimeException
    {
        $context = $this->instagramFailureContext($operation, $url, $e);

        Log::warning('Instagram API request failed.', $context);

        return new RuntimeException(
            "Instagram {$operation} failed: ".$context['meta_message'],
            0,
            $e
        );
    }

    private function logInstagramRequestFailure(string $operation, string $url, RequestException $e): void
    {
        Log::notice('Instagram API request failed.', $this->instagramFailureContext($operation, $url, $e));
    }

    /**
     * @return array<string, mixed>
     */
    private function instagramFailureContext(string $operation, string $url, RequestException $e): array
    {
        $response = $e->response;
        $payload = $response?->json();
        $message = data_get($payload, 'error.message')
            ?: Str::limit((string) ($response?->body() ?: $e->getMessage()), 300);

        return [
            'operation' => $operation,
            'url' => $url,
            'status' => $response?->status(),
            'meta_message' => $message,
            'meta_error_type' => data_get($payload, 'error.type'),
            'meta_error_code' => data_get($payload, 'error.code'),
            'meta_fbtrace_id' => data_get($payload, 'error.fbtrace_id'),
        ];
    }

    private function stateCacheKey(string $state): string
    {
        return self::STATE_CACHE_PREFIX.hash('sha256', $state);
    }

    private function assertConfigured(): void
    {
        foreach (['client_id', 'client_secret', 'redirect_uri'] as $key) {
            if (! filled(config("instagram.{$key}"))) {
                throw new RuntimeException("Instagram {$key} is not configured.");
            }
        }
    }

    private function assertUserOwnsProfile(Profile $profile, User $user): void
    {
        if ((int) $profile->user_id !== (int) $user->id) {
            throw new InvalidArgumentException('Profile not found.');
        }
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
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function unwrapDataObject(array $payload): array
    {
        $data = $payload['data'] ?? null;

        if (! is_array($data)) {
            return $payload;
        }

        if (array_is_list($data)) {
            return is_array($data[0] ?? null) ? $data[0] : $payload;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $token
     * @return array<int, string>
     */
    private function grantedScopes(array $token): array
    {
        $permissions = $token['permissions'] ?? [];

        if (is_string($permissions)) {
            $permissions = explode(',', $permissions);
        }

        return collect(is_array($permissions) ? $permissions : [])
            ->filter(fn ($permission): bool => is_scalar($permission))
            ->map(fn ($permission): string => trim((string) $permission))
            ->filter()
            ->values()
            ->all();
    }

    private function parseTimestamp(string $value): ?\DateTimeInterface
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
