<?php

namespace App\Services\Features;

use App\Models\FeatureFlag;
use App\Models\Profile;
use App\Models\ProfileFeatureSetting;
use App\Models\ProfileIntegration;
use Illuminate\Support\Collection;

class FeatureService
{
    public const BUSINESS = 'business';

    public const PRODUCTS = 'products';

    public const DOMAINS_CUSTOM = 'domains.custom';

    public const INTEGRATIONS_INSTAGRAM = 'integrations.instagram';

    public const INTEGRATIONS_TIKTOK = 'integrations.tiktok';

    public const INTEGRATIONS_ONLYFANS = 'integrations.onlyfans';

    public const INTEGRATIONS_OTHER = 'integrations.other';

    public const INTEGRATIONS_YOUTUBE = 'integrations.youtube';

    /**
     * @return array<string, array{group: string, key: string, name: string, provider?: string, profile_configurable?: bool}>
     */
    public function catalog(): array
    {
        return [
            self::PRODUCTS => [
                'group' => 'products',
                'key' => self::PRODUCTS,
                'name' => 'Products',
            ],
            self::BUSINESS => [
                'group' => 'business',
                'key' => self::BUSINESS,
                'name' => 'Business',
                'profile_configurable' => false,
            ],
            self::DOMAINS_CUSTOM => [
                'group' => 'domains',
                'key' => self::DOMAINS_CUSTOM,
                'name' => 'Custom domain',
                'profile_configurable' => false,
            ],
            self::INTEGRATIONS_INSTAGRAM => [
                'group' => 'integrations',
                'key' => self::INTEGRATIONS_INSTAGRAM,
                'name' => 'Instagram',
                'provider' => ProfileIntegration::PROVIDER_INSTAGRAM,
            ],
            self::INTEGRATIONS_TIKTOK => [
                'group' => 'integrations',
                'key' => self::INTEGRATIONS_TIKTOK,
                'name' => 'TikTok',
                'provider' => ProfileIntegration::PROVIDER_TIKTOK,
            ],
            self::INTEGRATIONS_ONLYFANS => [
                'group' => 'integrations',
                'key' => self::INTEGRATIONS_ONLYFANS,
                'name' => 'OnlyFans',
                'provider' => ProfileIntegration::PROVIDER_ONLYFANS,
            ],
            self::INTEGRATIONS_OTHER => [
                'group' => 'integrations',
                'key' => self::INTEGRATIONS_OTHER,
                'name' => 'Other',
                'provider' => ProfileIntegration::PROVIDER_OTHER,
            ],
            self::INTEGRATIONS_YOUTUBE => [
                'group' => 'integrations',
                'key' => self::INTEGRATIONS_YOUTUBE,
                'name' => 'YouTube',
                'provider' => ProfileIntegration::PROVIDER_YOUTUBE,
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function globalFeatureRows(): array
    {
        $flags = $this->globalFlagsByKey();

        return collect($this->catalog())
            ->map(fn (array $feature, string $key): array => [
                ...$feature,
                'enabled' => (bool) ($flags->get($key)?->enabled ?? false),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, bool>  $features
     * @return array<int, array<string, mixed>>
     */
    public function updateGlobalFeatures(array $features): array
    {
        foreach ($this->onlyCatalogKeys($features) as $key => $enabled) {
            $definition = $this->catalog()[$key];

            FeatureFlag::query()->updateOrCreate(
                ['key' => $key],
                [
                    'enabled' => (bool) $enabled,
                    'metadata' => [
                        'group' => $definition['group'],
                        ...(isset($definition['provider']) ? ['provider' => $definition['provider']] : []),
                        ...(array_key_exists('profile_configurable', $definition)
                            ? ['profile_configurable' => $definition['profile_configurable']]
                            : []),
                    ],
                    'name' => $definition['name'],
                ]
            );
        }

        return $this->globalFeatureRows();
    }

    public function isGlobalEnabled(string $key): bool
    {
        return (bool) ($this->globalFlagsByKey()->get($key)?->enabled ?? false);
    }

    public function isProfileFeatureEnabled(Profile $profile, string $key): bool
    {
        if (! $this->isGlobalEnabled($key)) {
            return false;
        }

        $stored = $profile->featureSettings()
            ->where('feature_key', $key)
            ->first();

        if ($stored instanceof ProfileFeatureSetting) {
            return (bool) $stored->enabled;
        }

        return $this->defaultProfileFeatureEnabled();
    }

    public function isProfileIntegrationEnabled(Profile $profile, string $provider): bool
    {
        $key = $this->integrationFeatureKey($provider);

        return $key !== null && $this->isProfileFeatureEnabled($profile, $key);
    }

    /**
     * @return array<int, string>
     */
    public function enabledIntegrationProviders(Profile $profile): array
    {
        return collect($this->catalog())
            ->filter(fn (array $feature): bool => ($feature['group'] ?? null) === 'integrations')
            ->filter(fn (array $feature): bool => $this->isProfileFeatureEnabled($profile, $feature['key']))
            ->pluck('provider')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function disabledCatalogIntegrationProviders(Profile $profile): array
    {
        return collect($this->catalog())
            ->filter(fn (array $feature): bool => ($feature['group'] ?? null) === 'integrations')
            ->filter(fn (array $feature): bool => ! $this->isProfileFeatureEnabled($profile, $feature['key']))
            ->pluck('provider')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function profileFeatureRows(Profile $profile): array
    {
        $flags = $this->globalFlagsByKey();
        $settings = $profile->featureSettings()
            ->get()
            ->keyBy('feature_key');

        return collect($this->catalog())
            ->map(function (array $feature, string $key) use ($flags, $settings): array {
                $globallyEnabled = (bool) ($flags->get($key)?->enabled ?? false);
                $profileConfigurable = (bool) ($feature['profile_configurable'] ?? true);
                $stored = $settings->get($key);
                $enabled = $profileConfigurable
                    ? ($stored instanceof ProfileFeatureSetting
                        ? (bool) $stored->enabled
                        : $this->defaultProfileFeatureEnabled())
                    : $globallyEnabled;

                return [
                    ...$feature,
                    'available' => $globallyEnabled,
                    'enabled' => $enabled,
                    'effective' => $globallyEnabled && $enabled,
                    'globally_enabled' => $globallyEnabled,
                    'profile_configurable' => $profileConfigurable,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, bool>  $features
     * @return array<int, array<string, mixed>>
     */
    public function updateProfileFeatures(Profile $profile, array $features): array
    {
        foreach ($this->onlyProfileCatalogKeys($features) as $key => $enabled) {
            ProfileFeatureSetting::query()->updateOrCreate(
                ['feature_key' => $key, 'profile_id' => $profile->id],
                ['enabled' => (bool) $enabled]
            );
        }

        return $this->profileFeatureRows($profile);
    }

    public function initializeProfileFeatures(Profile $profile, bool $enabled = false): void
    {
        $this->updateProfileFeatures(
            $profile,
            array_fill_keys(array_keys($this->profileCatalog()), $enabled)
        );
    }

    public function integrationFeatureKey(string $provider): ?string
    {
        return match ($provider) {
            ProfileIntegration::PROVIDER_INSTAGRAM => self::INTEGRATIONS_INSTAGRAM,
            ProfileIntegration::PROVIDER_TIKTOK => self::INTEGRATIONS_TIKTOK,
            ProfileIntegration::PROVIDER_ONLYFANS => self::INTEGRATIONS_ONLYFANS,
            ProfileIntegration::PROVIDER_OTHER => self::INTEGRATIONS_OTHER,
            ProfileIntegration::PROVIDER_YOUTUBE => self::INTEGRATIONS_YOUTUBE,
            default => null,
        };
    }

    /**
     * @return Collection<string, FeatureFlag>
     */
    private function globalFlagsByKey(): Collection
    {
        return FeatureFlag::query()
            ->whereIn('key', array_keys($this->catalog()))
            ->get()
            ->keyBy('key');
    }

    /**
     * @param  array<string, bool>  $features
     * @return array<string, bool>
     */
    private function onlyCatalogKeys(array $features): array
    {
        return array_intersect_key($features, $this->catalog());
    }

    /**
     * @return array<string, array{group: string, key: string, name: string, provider?: string, profile_configurable?: bool}>
     */
    private function profileCatalog(): array
    {
        return array_filter(
            $this->catalog(),
            fn (array $feature): bool => (bool) ($feature['profile_configurable'] ?? true)
        );
    }

    /**
     * @param  array<string, bool>  $features
     * @return array<string, bool>
     */
    private function onlyProfileCatalogKeys(array $features): array
    {
        return array_intersect_key($features, $this->profileCatalog());
    }

    private function defaultProfileFeatureEnabled(): bool
    {
        return false;
    }
}
