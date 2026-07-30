<?php

namespace App\Services\Integrations;

use App\Classes\Subscriptions\SubscriptionPlanCapabilityService;
use App\Models\Message;
use App\Models\Profile;
use App\Models\ProfileIntegrationMedia;
use App\Services\Features\FeatureService;

class ProfileMediaPromptService
{
    public function __construct(
        private readonly FeatureService $features,
        private readonly SubscriptionPlanCapabilityService $capabilities,
    ) {}

    /**
     * @return array{
     *     wants_media: bool,
     *     use_unseen: bool,
     *     constraints: array<string, mixed>,
     *     included_providers: array<int, string>,
     *     excluded_providers: array<int, string>,
     *     included_source_types: array<int, string>,
     *     excluded_source_types: array<int, string>,
     *     shown_media_ids: array<int, int>,
     *     available_media: array<int, array<string, mixed>>,
     *     candidate_media: array<int, array<string, mixed>>,
     *     available_provider_labels: array<int, string>,
     *     candidate_provider_labels: array<int, string>
     * }
     */
    public function analyze(Profile $profile, string $text, ?int $chatId = null, ?int $currentMessageId = null): array
    {
        $availableMedia = $this->selectedMediaForPrompt($profile);
        $excludedProviders = $this->excludedProviderKeys($text, $availableMedia);
        $includedProviders = array_values(array_diff($this->includedProviderKeys($text, $availableMedia), $excludedProviders));
        $recentMentionsMedia = $this->recentConversationMentionsMedia($profile, $chatId, $currentMessageId);
        $shownMediaIds = $this->recentShownMediaIds($profile, $chatId, $currentMessageId);
        $wantsMedia = $this->looksLikeMediaRequest($text)
            || ($this->looksLikeAnotherMediaRequest($text) && $shownMediaIds !== [])
            || ($this->looksLikeAnyMediaChoice($text) && $recentMentionsMedia);
        $useUnseen = ($this->looksLikeAnotherMediaRequest($text) && $shownMediaIds !== [])
            || ($this->looksLikeAnyMediaChoice($text) && $recentMentionsMedia && $shownMediaIds !== []);

        $constraints = [
            'include_providers' => $includedProviders,
            'exclude_providers' => $excludedProviders,
            'include_source_types' => [],
            'exclude_source_types' => [],
            'require_unseen' => $useUnseen,
        ];
        $candidateMedia = $this->candidateMediaForConstraints($availableMedia, $constraints, $shownMediaIds);

        return [
            'wants_media' => $wantsMedia,
            'use_unseen' => $useUnseen,
            'constraints' => $constraints,
            'included_providers' => $includedProviders,
            'excluded_providers' => $excludedProviders,
            'included_source_types' => [],
            'excluded_source_types' => [],
            'shown_media_ids' => $shownMediaIds,
            'available_media' => $availableMedia,
            'candidate_media' => $candidateMedia,
            'available_provider_labels' => $this->providerLabels($availableMedia),
            'candidate_provider_labels' => $this->providerLabels($candidateMedia),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $structuredAnswer
     * @return array{
     *     wants_media: bool,
     *     use_unseen: bool,
     *     constraints: array<string, mixed>,
     *     included_providers: array<int, string>,
     *     excluded_providers: array<int, string>,
     *     included_source_types: array<int, string>,
     *     excluded_source_types: array<int, string>,
     *     shown_media_ids: array<int, int>,
     *     available_media: array<int, array<string, mixed>>,
     *     candidate_media: array<int, array<string, mixed>>,
     *     available_provider_labels: array<int, string>,
     *     candidate_provider_labels: array<int, string>
     * }
     */
    public function analyzeStructuredRequest(Profile $profile, ?array $structuredAnswer, ?int $chatId = null, ?int $currentMessageId = null): array
    {
        $availableMedia = $this->selectedMediaForPrompt($profile);
        $shownMediaIds = $this->recentShownMediaIds($profile, $chatId, $currentMessageId);
        $constraints = $this->normalizeConstraints($structuredAnswer['constraints'] ?? []);
        $wantsMedia = (bool) ($structuredAnswer['media_request'] ?? false)
            || (($structuredAnswer['media_action'] ?? null) === 'show')
            || (($structuredAnswer['media_ids'] ?? []) !== []);
        $candidateMedia = $this->candidateMediaForConstraints($availableMedia, $constraints, $shownMediaIds);

        return [
            'wants_media' => $wantsMedia,
            'use_unseen' => (bool) $constraints['require_unseen'],
            'constraints' => $constraints,
            'included_providers' => $constraints['include_providers'],
            'excluded_providers' => $constraints['exclude_providers'],
            'included_source_types' => $constraints['include_source_types'],
            'excluded_source_types' => $constraints['exclude_source_types'],
            'shown_media_ids' => $shownMediaIds,
            'available_media' => $availableMedia,
            'candidate_media' => $candidateMedia,
            'available_provider_labels' => $this->providerLabels($availableMedia),
            'candidate_provider_labels' => $this->providerLabels($candidateMedia),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function selectedMediaForPrompt(Profile $profile): array
    {
        $disabledProviders = $this->features->disabledCatalogIntegrationProviders($profile);
        $providers = $profile->integrationMedia()
            ->where('selected', true)
            ->distinct()
            ->pluck('provider')
            ->reject(fn (string $provider): bool => in_array($provider, $disabledProviders, true));

        return $providers
            ->flatMap(function (string $provider) use ($profile) {
                return $profile->integrationMedia()
                    ->where('provider', $provider)
                    ->where('selected', true)
                    ->orderByDesc('taken_at')
                    ->orderByDesc('id')
                    ->limit($this->capabilities->selectedMediaPerProfile($profile, $provider))
                    ->get();
            })
            ->sortByDesc(fn (ProfileIntegrationMedia $media): string => sprintf(
                '%s-%020d',
                $media->taken_at?->format('Y-m-d H:i:s.u') ?? '',
                $media->id
            ))
            ->values()
            ->map(fn (ProfileIntegrationMedia $media): array => [
                'id' => $media->id,
                'provider' => $this->providerLabel($media->provider),
                'provider_key' => $media->provider,
                'provider_label' => $this->providerLabel($media->provider),
                'source_type' => $this->sourceTypeForProvider($media->provider),
                'media_type' => $media->media_type,
                'image_url' => $media->thumbnail_url
                    ?: (str_contains(strtoupper((string) $media->media_type), 'VIDEO') ? null : $media->media_url),
                'media_url' => $media->media_url,
                'thumbnail_url' => $media->thumbnail_url,
                'permalink' => $media->permalink,
                'caption' => $media->caption,
                'observation' => filled($media->observation) ? $media->observation : $media->caption,
                'age_restricted' => $media->age_restricted,
                'taken_at' => $media->taken_at?->toDateString(),
            ])
            ->filter(fn (array $media): bool => filled($media['media_url'] ?? null) || filled($media['permalink'] ?? null))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $availableMedia
     * @param  array<string, mixed>  $constraints
     * @param  array<int, int>  $shownMediaIds
     * @return array<int, array<string, mixed>>
     */
    public function candidateMediaForConstraints(array $availableMedia, array $constraints, array $shownMediaIds = []): array
    {
        $constraints = $this->normalizeConstraints($constraints);
        $includedProviders = $constraints['include_providers'];
        $excludedProviders = $constraints['exclude_providers'];
        $includedSourceTypes = $constraints['include_source_types'];
        $excludedSourceTypes = $constraints['exclude_source_types'];

        return collect($availableMedia)
            ->filter(function (array $media) use ($includedProviders, $excludedProviders, $includedSourceTypes, $excludedSourceTypes): bool {
                $providerKey = $this->normalizeProviderKey($media['provider_key'] ?? null);

                if ($providerKey === null) {
                    return false;
                }

                if ($includedProviders !== [] && ! in_array($providerKey, $includedProviders, true)) {
                    return false;
                }

                if (in_array($providerKey, $excludedProviders, true)) {
                    return false;
                }

                $sourceType = $this->normalizeSourceType($media['source_type'] ?? null)
                    ?? $this->sourceTypeForProvider($providerKey);

                if ($includedSourceTypes !== [] && ! in_array($sourceType, $includedSourceTypes, true)) {
                    return false;
                }

                return ! in_array($sourceType, $excludedSourceTypes, true);
            })
            ->when((bool) $constraints['require_unseen'], fn ($items) => $items->filter(function (array $media) use ($shownMediaIds): bool {
                $mediaId = (int) ($media['id'] ?? 0);

                return $mediaId <= 0 || ! in_array($mediaId, $shownMediaIds, true);
            }))
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     include_providers: array<int, string>,
     *     exclude_providers: array<int, string>,
     *     include_source_types: array<int, string>,
     *     exclude_source_types: array<int, string>,
     *     require_unseen: bool
     * }
     */
    public function normalizeConstraints(mixed $constraints): array
    {
        if (! is_array($constraints)) {
            $constraints = [];
        }

        return [
            'include_providers' => $this->normalizeProviderList($constraints['include_providers'] ?? $constraints['included_providers'] ?? []),
            'exclude_providers' => $this->normalizeProviderList($constraints['exclude_providers'] ?? $constraints['excluded_providers'] ?? []),
            'include_source_types' => $this->normalizeSourceTypeList($constraints['include_source_types'] ?? $constraints['included_source_types'] ?? []),
            'exclude_source_types' => $this->normalizeSourceTypeList($constraints['exclude_source_types'] ?? $constraints['excluded_source_types'] ?? []),
            'require_unseen' => (bool) ($constraints['require_unseen'] ?? $constraints['needs_unseen'] ?? $constraints['use_unseen'] ?? false),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $media
     * @return array<int, string>
     */
    public function providerLabels(array $media): array
    {
        return collect($media)
            ->map(fn (array $item): ?string => is_string($item['provider_label'] ?? null) ? trim($item['provider_label']) : null)
            ->filter(fn (?string $label): bool => $label !== null && $label !== '')
            ->unique()
            ->values()
            ->all();
    }

    public function providerLabel(string $provider): string
    {
        $provider = $this->normalizeProviderKey($provider) ?? $provider;

        return match ($provider) {
            'facebook' => 'Facebook',
            'instagram' => 'Instagram',
            'linkedin' => 'LinkedIn',
            'onlyfans' => 'OnlyFans',
            'tiktok' => 'TikTok',
            'x' => 'X',
            'youtube' => 'YouTube',
            default => str($provider)->replace(['_', '-'], ' ')->title()->toString(),
        };
    }

    public function sourceTypeForProvider(string $provider): string
    {
        return match ($this->normalizeProviderKey($provider)) {
            'facebook', 'instagram', 'linkedin', 'onlyfans', 'tiktok', 'x', 'youtube' => 'social_network',
            default => 'integration',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $availableMedia
     * @return array<int, string>
     */
    public function excludedProviderKeys(string $text, array $availableMedia = []): array
    {
        return collect($this->providerAliases($availableMedia))
            ->filter(fn (array $aliases, string $provider): bool => $this->textExcludesProvider($text, $aliases))
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $availableMedia
     * @return array<int, string>
     */
    public function includedProviderKeys(string $text, array $availableMedia = []): array
    {
        $excluded = $this->excludedProviderKeys($text, $availableMedia);

        return collect($this->providerAliases($availableMedia))
            ->filter(fn (array $aliases, string $provider): bool => ! in_array($provider, $excluded, true) && $this->textMentionsProvider($text, $aliases))
            ->keys()
            ->values()
            ->all();
    }

    public function looksLikeMediaRequest(string $text): bool
    {
        $normalized = mb_strtolower($text);

        foreach ([
            'foto',
            'fotos',
            'imagen',
            'imágenes',
            'post',
            'publicación',
            'video',
            'videos',
            'clip',
            'clips',
            'media',
            'onlyfans',
            'only fans',
            'tiktok',
            'photo',
            'picture',
            'image',
            'visual',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function looksLikeAnotherMediaRequest(string $text): bool
    {
        $normalized = mb_strtolower($text);
        $asksAnother = preg_match('/\b(otra|otro|otras|otros|another|more|m[aá]s)\b/u', $normalized) === 1;
        $hasShowIntent = preg_match('/\b(muestra|mu[eé]strame|ens[eé][ñn]ame|ver|ve|quiero|show|see|view)\b/u', $normalized) === 1;
        $isShortFollowUp = preg_match('/^\s*(otra|otro|another|more|m[aá]s)\s*[?!.]*\s*$/u', $normalized) === 1;

        return $asksAnother && ($hasShowIntent || $isShortFollowUp || $this->looksLikeMediaRequest($text));
    }

    public function looksLikeAnyMediaChoice(string $text): bool
    {
        $normalized = trim(mb_strtolower($text));

        if (mb_strlen($normalized) > 48) {
            return false;
        }

        return preg_match('/\b(cualquier|cualquiera|whatever|whichever)\b/u', $normalized) === 1
            || preg_match('/\b(la|el|una|uno)\s+que\s+quieras\b/u', $normalized) === 1
            || preg_match('/\b(any|anyone|anything)\b/u', $normalized) === 1;
    }

    private function recentConversationMentionsMedia(Profile $profile, ?int $chatId, ?int $currentMessageId): bool
    {
        if (! $profile->exists || ! $chatId) {
            return false;
        }

        return $profile->messages()
            ->where('chat_id', $chatId)
            ->when($currentMessageId, fn ($query) => $query->where('id', '<', $currentMessageId))
            ->orderByDesc('id')
            ->limit(6)
            ->pluck('text')
            ->contains(fn ($text): bool => is_string($text) && $this->looksLikeMediaRequest($text));
    }

    /**
     * @return array<int>
     */
    private function recentShownMediaIds(Profile $profile, ?int $chatId, ?int $currentMessageId): array
    {
        if (! $profile->exists || ! $chatId) {
            return [];
        }

        return $profile->messages()
            ->where('chat_id', $chatId)
            ->where('type', 'answer')
            ->when($currentMessageId, fn ($query) => $query->where('id', '<', $currentMessageId))
            ->orderByDesc('id')
            ->limit(10)
            ->get(['data'])
            ->flatMap(function (Message $message): array {
                $media = $message->data['media'] ?? [];

                if (! is_array($media)) {
                    return [];
                }

                return collect($media)
                    ->map(fn ($item): int => is_array($item) ? (int) ($item['id'] ?? 0) : 0)
                    ->filter(fn (int $id): bool => $id > 0)
                    ->all();
            })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $availableMedia
     * @return array<string, array<int, string>>
     */
    private function providerAliases(array $availableMedia): array
    {
        $aliases = [
            'facebook' => ['facebook', 'fb'],
            'instagram' => ['instagram', 'insta', 'ig'],
            'linkedin' => ['linkedin', 'linked in'],
            'onlyfans' => ['onlyfans', 'only fans'],
            'tiktok' => ['tiktok', 'tik tok', 'tiktojk', 'ticktok'],
            'x' => ['x', 'twitter'],
            'youtube' => ['youtube', 'you tube'],
        ];

        foreach ($availableMedia as $media) {
            $providerKey = $this->normalizeProviderKey($media['provider_key'] ?? null);

            if ($providerKey === null) {
                continue;
            }

            $aliases[$providerKey] ??= [$providerKey];
            $aliases[$providerKey][] = str_replace(['_', '-'], ' ', $providerKey);

            if (is_string($media['provider_label'] ?? null)) {
                $aliases[$providerKey][] = (string) $media['provider_label'];
            }
        }

        return collect($aliases)
            ->map(fn (array $values): array => collect($values)
                ->map(fn (string $value): string => trim(mb_strtolower($value)))
                ->filter()
                ->unique()
                ->values()
                ->all())
            ->all();
    }

    /**
     * @param  array<int, string>  $aliases
     */
    private function textMentionsProvider(string $text, array $aliases): bool
    {
        foreach ($aliases as $alias) {
            if (preg_match('/(?<![\pL\pN])'.preg_quote($alias, '/').'(?![\pL\pN])/iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $aliases
     */
    private function textExcludesProvider(string $text, array $aliases): bool
    {
        foreach ($aliases as $alias) {
            $providerPattern = preg_quote($alias, '/');
            $patterns = [
                '/\b(?:sin|excepto|salvo)\b[^.?!]{0,50}(?<![\pL\pN])'.$providerPattern.'(?![\pL\pN])/iu',
                '/\bfuera\s+de\b[^.?!]{0,50}(?<![\pL\pN])'.$providerPattern.'(?![\pL\pN])/iu',
                '/\b(?:distint[oa]s?|diferente[s]?)\s+de\b[^.?!]{0,50}(?<![\pL\pN])'.$providerPattern.'(?![\pL\pN])/iu',
                '/\bno\s+(?:de|en)\b[^.?!]{0,50}(?<![\pL\pN])'.$providerPattern.'(?![\pL\pN])/iu',
                '/\bque\s+no\s+(?:sea|sean|est[eé]|est[eé]n|tenga|tengan|venga|vengan|provenga|provengan)(?:\s+(?:en|de))?\b[^.?!]{0,50}(?<![\pL\pN])'.$providerPattern.'(?![\pL\pN])/iu',
                '/\bno\s+(?:sea|sean|est[eé]|est[eé]n|tenga|tengan|venga|vengan|provenga|provengan)(?:\s+(?:en|de))?\b[^.?!]{0,50}(?<![\pL\pN])'.$providerPattern.'(?![\pL\pN])/iu',
                '/\b(?:not|without|except|exclude|outside|other\s+than)\b[^.?!]{0,50}(?<![\pL\pN])'.$providerPattern.'(?![\pL\pN])/iu',
                '/\bnot\s+(?:on|from|in)\b[^.?!]{0,50}(?<![\pL\pN])'.$providerPattern.'(?![\pL\pN])/iu',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $text) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeProviderKey(mixed $provider): ?string
    {
        if (! is_scalar($provider)) {
            return null;
        }

        $provider = trim(mb_strtolower((string) $provider));
        $provider = str_replace([' ', '_'], '-', $provider);
        $provider = match ($provider) {
            'fb' => 'facebook',
            'ig', 'insta' => 'instagram',
            'tik-tok', 'tiktojk', 'ticktok' => 'tiktok',
            'twitter' => 'x',
            'you-tube' => 'youtube',
            default => $provider,
        };

        return $provider !== '' ? $provider : null;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeProviderList(mixed $providers): array
    {
        if (is_scalar($providers)) {
            $providers = [$providers];
        }

        if (! is_array($providers)) {
            return [];
        }

        return collect($providers)
            ->map(fn ($provider): ?string => $this->normalizeProviderKey($provider))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function normalizeSourceTypeList(mixed $sourceTypes): array
    {
        if (is_scalar($sourceTypes)) {
            $sourceTypes = [$sourceTypes];
        }

        if (! is_array($sourceTypes)) {
            return [];
        }

        return collect($sourceTypes)
            ->map(fn ($sourceType): ?string => $this->normalizeSourceType($sourceType))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeSourceType(mixed $sourceType): ?string
    {
        if (! is_scalar($sourceType)) {
            return null;
        }

        $sourceType = trim(mb_strtolower((string) $sourceType));
        $sourceType = str_replace([' ', '-'], '_', $sourceType);
        $sourceType = match ($sourceType) {
            'social', 'social_media', 'social_networks', 'redes_sociales' => 'social_network',
            default => $sourceType,
        };

        return $sourceType !== '' ? $sourceType : null;
    }
}
