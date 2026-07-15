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
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class InstagramIntegrationService
{
    private const STATE_CACHE_PREFIX = 'instagram_oauth_state:';

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

        $shortToken = $this->exchangeCodeForToken($code);
        $token = $this->exchangeLongLivedToken($shortToken['access_token'] ?? null) ?: $shortToken;
        $accessToken = (string) ($token['access_token'] ?? $shortToken['access_token'] ?? '');

        if ($accessToken === '') {
            throw new RuntimeException('Instagram did not return an access token.');
        }

        $account = $this->fetchAccount($accessToken);

        return ProfileIntegration::updateOrCreate(
            [
                'profile_id' => $profile->id,
                'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            ],
            [
                'user_id' => $user->id,
                'provider_user_id' => $this->scalarString($account['id'] ?? $shortToken['user_id'] ?? null),
                'username' => $this->scalarString($account['username'] ?? null),
                'access_token' => $accessToken,
                'token_type' => $this->scalarString($token['token_type'] ?? 'bearer'),
                'scopes' => config('instagram.scopes', ['instagram_business_basic']),
                'expires_at' => isset($token['expires_in']) ? now()->addSeconds((int) $token['expires_in']) : null,
                'status' => ProfileIntegration::STATUS_CONNECTED,
                'metadata' => array_filter([
                    'account' => $account,
                    'short_token_user_id' => $shortToken['user_id'] ?? null,
                ]),
            ]
        );
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

        $account = $this->fetchAccount($accessToken);
        $media = $this->fetchMedia($accessToken);

        DB::transaction(function () use ($account, $integration, $media): void {
            $integration->forceFill([
                'provider_user_id' => $this->scalarString($account['id'] ?? $integration->provider_user_id),
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
                $isNew = ! $media->exists;

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

                if ($isNew && is_string($caption) && trim($caption) !== '') {
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
        $selectionLimit = max(1, (int) config('instagram.selection_limit', 10));

        if ($selectedItems->count() > $selectionLimit) {
            throw new InvalidArgumentException("You can select up to {$selectionLimit} Instagram items.");
        }

        $mediaIds = $integration->media()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $itemsById = collect($items)->keyBy(fn (array $item): int => (int) $item['id']);

        DB::transaction(function () use ($integration, $itemsById, $mediaIds): void {
            foreach ($mediaIds as $mediaId) {
                $item = $itemsById->get($mediaId);

                ProfileIntegrationMedia::query()
                    ->where('profile_integration_id', $integration->id)
                    ->whereKey($mediaId)
                    ->update([
                        'selected' => (bool) ($item['selected'] ?? false),
                        'observation' => isset($item['observation'])
                            ? trim((string) $item['observation'])
                            : null,
                        'updated_at' => now(),
                    ]);
            }
        });

        return $integration->fresh(['media']);
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
            ->limit(max(1, (int) config('instagram.selection_limit', 10)))
            ->get()
            ->map(fn (ProfileIntegrationMedia $media): array => [
                'id' => $media->id,
                'provider' => 'Instagram',
                'provider_key' => ProfileIntegration::PROVIDER_INSTAGRAM,
                'provider_label' => 'Instagram',
                'media_type' => $media->media_type,
                'image_url' => $media->thumbnail_url ?: $media->media_url,
                'permalink' => $media->permalink,
                'caption' => $media->caption,
                'observation' => $media->observation,
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
        return Http::asForm()
            ->post((string) config('instagram.token_url'), [
                'client_id' => config('instagram.client_id'),
                'client_secret' => config('instagram.client_secret'),
                'grant_type' => 'authorization_code',
                'redirect_uri' => config('instagram.redirect_uri'),
                'code' => $code,
            ])
            ->throw()
            ->json();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function exchangeLongLivedToken(?string $shortAccessToken): ?array
    {
        if (! $shortAccessToken) {
            return null;
        }

        try {
            return Http::get($this->graphUrl('/access_token'), [
                'grant_type' => 'ig_exchange_token',
                'client_secret' => config('instagram.client_secret'),
                'access_token' => $shortAccessToken,
            ])->throw()->json();
        } catch (RequestException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchAccount(string $accessToken): array
    {
        return Http::get($this->graphUrl('/me'), [
            'access_token' => $accessToken,
            'fields' => 'id,username,account_type,media_count',
        ])->throw()->json();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchMedia(string $accessToken): array
    {
        $response = Http::get($this->graphUrl('/me/media'), [
            'access_token' => $accessToken,
            'fields' => 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp',
            'limit' => max(1, (int) config('instagram.media_limit', 100)),
        ])->throw()->json();

        return array_values(array_filter(
            Arr::wrap($response['data'] ?? []),
            fn ($item): bool => is_array($item)
        ));
    }

    private function graphUrl(string $path): string
    {
        return rtrim((string) config('instagram.graph_base_url'), '/').'/'.ltrim($path, '/');
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

    private function parseTimestamp(string $value): ?\DateTimeInterface
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
