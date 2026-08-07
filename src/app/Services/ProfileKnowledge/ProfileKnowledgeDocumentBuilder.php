<?php

namespace App\Services\ProfileKnowledge;

use App\Enums\ProfileFactVisibility;
use App\Enums\ProfileProductStatus;
use App\Models\Profile;
use App\Models\ProfileIntegrationMedia;
use App\Models\ProfileProduct;
use App\Models\ProfileSource;
use App\Models\ProfileSourceItem;
use App\Services\Integrations\IntegrationDestinationCatalog;
use Illuminate\Support\Str;

class ProfileKnowledgeDocumentBuilder
{
    private const EXCLUDED_PROFILE_DATA_KEYS = [
        'networks',
        'voice_enabled',
        'voice_autoplay_enabled',
    ];

    public function __construct(private readonly IntegrationDestinationCatalog $destinations) {}

    /**
     * @return array<int, ProfileKnowledgeDocument>
     */
    public function build(Profile $profile): array
    {
        $profile->loadMissing([
            'sources.items',
            'facts',
            'integrationMedia.integration',
            'products',
        ]);

        return collect()
            ->merge($this->identityDocuments($profile))
            ->merge($this->profileDataDocuments($profile))
            ->merge($this->sourceDocuments($profile))
            ->merge($this->factDocuments($profile))
            ->merge($this->socialDocuments($profile))
            ->merge($this->mediaDocuments($profile))
            ->merge($this->productDocuments($profile))
            ->merge($this->productGuidanceDocuments($profile))
            ->filter(fn (ProfileKnowledgeDocument $document): bool => trim($document->content) !== '')
            ->values()
            ->all();
    }

    /** @return array<int, ProfileKnowledgeDocument> */
    private function identityDocuments(Profile $profile): array
    {
        $parts = array_filter([
            filled($profile->name) ? "Name: {$profile->name}" : null,
            filled($profile->description) ? "Description: {$profile->description}" : null,
            filled($profile->genre) ? "Gender: {$profile->genre}" : null,
            filled($profile->personality) ? "Personality: {$profile->personality}" : null,
            filled($profile->profession_key) ? "Profession: {$profile->profession_key}" : null,
        ]);

        return [new ProfileKnowledgeDocument(
            key: 'profile.identity',
            sourceType: 'profile_identity',
            sourceId: (string) $profile->id,
            content: implode("\n", $parts),
            metadata: ['profile_id' => $profile->id],
            locale: $profile->locale,
        )];
    }

    /** @return array<int, ProfileKnowledgeDocument> */
    private function profileDataDocuments(Profile $profile): array
    {
        $documents = [];

        foreach ((array) $profile->data as $section => $value) {
            if (in_array((string) $section, self::EXCLUDED_PROFILE_DATA_KEYS, true) || $this->emptyValue($value)) {
                continue;
            }

            $items = is_array($value) && array_is_list($value) ? $value : [$value];

            foreach ($items as $index => $item) {
                if ($this->emptyValue($item)) {
                    continue;
                }

                $encoded = $this->encodeValue($item);
                $baseKey = 'profile.data.'.Str::slug((string) $section, '_').'.'.$index;

                foreach ($this->splitText($encoded) as $part => $content) {
                    $documents[] = new ProfileKnowledgeDocument(
                        key: $baseKey.'.'.$part,
                        sourceType: 'profile_data',
                        sourceId: (string) $section,
                        content: "Profile section {$section}: {$content}",
                        metadata: ['section' => (string) $section, 'item' => $index],
                        locale: $profile->locale,
                    );
                }
            }
        }

        return $documents;
    }

    /** @return array<int, ProfileKnowledgeDocument> */
    private function sourceDocuments(Profile $profile): array
    {
        $documents = [];

        foreach ($profile->sources as $source) {
            if (
                ! $source instanceof ProfileSource
                || $source->approved_at === null
                || $source->duplicate_of_source_id !== null
            ) {
                continue;
            }

            $approvedItems = $source->items->filter(fn (ProfileSourceItem $item): bool => $item->approved);

            foreach ($approvedItems as $item) {
                $content = trim(implode("\n", array_filter([
                    "Source: {$source->name} (ID {$source->id})",
                    filled($item->title) ? "Title: {$item->title}" : null,
                    "Type: {$item->type}",
                    $item->content,
                ])));

                foreach ($this->splitText($content) as $part => $chunk) {
                    $documents[] = new ProfileKnowledgeDocument(
                        key: "source.item.{$item->id}.{$part}",
                        sourceType: 'profile_source_item',
                        sourceId: (string) $item->id,
                        content: $chunk,
                        metadata: [
                            'profile_source_id' => $source->id,
                            'item_type' => $item->type,
                            'title' => $item->title,
                            'source_url' => $item->source_url,
                        ],
                        locale: $profile->locale,
                    );
                }
            }

            if ($approvedItems->isEmpty() && filled($source->extracted_text)) {
                foreach ($this->splitText((string) $source->extracted_text) as $part => $chunk) {
                    $documents[] = new ProfileKnowledgeDocument(
                        key: "source.raw.{$source->id}.{$part}",
                        sourceType: 'profile_source',
                        sourceId: (string) $source->id,
                        content: "Source {$source->name}: {$chunk}",
                        metadata: ['source_type' => $source->type->value, 'name' => $source->name],
                        locale: $profile->locale,
                    );
                }
            }
        }

        return $documents;
    }

    /** @return array<int, ProfileKnowledgeDocument> */
    private function factDocuments(Profile $profile): array
    {
        $duplicateSourceIds = $profile->sources
            ->whereNotNull('duplicate_of_source_id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $profile->facts
            ->filter(fn ($fact): bool => $fact->approved
                && $fact->visibility === ProfileFactVisibility::Public
                && ! in_array((int) $fact->profile_source_id, $duplicateSourceIds, true))
            ->flatMap(function ($fact) use ($profile): array {
                $documents = [];

                foreach ($this->splitText("{$fact->category}: {$fact->text}") as $part => $content) {
                    $documents[] = new ProfileKnowledgeDocument(
                        key: "fact.{$fact->id}.{$part}",
                        sourceType: 'profile_fact',
                        sourceId: (string) $fact->id,
                        content: $content,
                        metadata: [
                            'category' => $fact->category,
                            'profile_source_id' => $fact->profile_source_id,
                            'profile_source_item_id' => $fact->profile_source_item_id,
                        ],
                        locale: $profile->locale,
                    );
                }

                return $documents;
            })
            ->values()
            ->all();
    }

    /** @return array<int, ProfileKnowledgeDocument> */
    private function socialDocuments(Profile $profile): array
    {
        return collect((array) $profile->networks)
            ->filter(fn ($url): bool => is_scalar($url) && filled((string) $url))
            ->map(fn ($url, string $network): ProfileKnowledgeDocument => new ProfileKnowledgeDocument(
                key: 'social.'.Str::slug($network, '_'),
                sourceType: 'social_link',
                sourceId: $network,
                content: "Social network {$network}: ".trim((string) $url),
                metadata: ['network' => $network, 'url' => trim((string) $url)],
                locale: $profile->locale,
            ))
            ->values()
            ->all();
    }

    /** @return array<int, ProfileKnowledgeDocument> */
    private function mediaDocuments(Profile $profile): array
    {
        return $profile->integrationMedia
            ->map(function (ProfileIntegrationMedia $media) use ($profile): ProfileKnowledgeDocument {
                $destination = $this->destinations->labelsForMedia($media->metadata, $profile->locale);
                $content = implode("\n", array_filter([
                    "Integration media ID: {$media->id}",
                    "Provider: {$media->provider}",
                    filled($destination['destination_label']) ? "Destination: {$destination['destination_label']}" : null,
                    filled($media->media_type) ? "Media type: {$media->media_type}" : null,
                    filled($media->caption) ? "Caption: {$media->caption}" : null,
                    filled($media->observation) ? "Observation: {$media->observation}" : null,
                    $media->taken_at ? 'Date: '.$media->taken_at->toDateString() : null,
                    $media->age_restricted ? 'Age restricted: yes' : null,
                ]));

                return new ProfileKnowledgeDocument(
                    key: "integration.media.{$media->id}",
                    sourceType: 'integration_media',
                    sourceId: (string) $media->id,
                    content: $content,
                    metadata: [
                        'provider' => $media->provider,
                        'selected' => (bool) $media->selected,
                        'media_type' => $media->media_type,
                        'destination_type' => $destination['destination_type'],
                        'destination_label' => $destination['destination_label'],
                        'action_type' => $destination['action_type'],
                        'action_label' => $destination['action_label'],
                        'permalink' => $media->permalink,
                    ],
                    active: (bool) $media->selected,
                    locale: $profile->locale,
                );
            })
            ->values()
            ->all();
    }

    /** @return array<int, ProfileKnowledgeDocument> */
    private function productDocuments(Profile $profile): array
    {
        return $profile->products
            ->map(fn (ProfileProduct $product): ProfileKnowledgeDocument => new ProfileKnowledgeDocument(
                key: "product.{$product->id}",
                sourceType: 'product',
                sourceId: (string) $product->id,
                content: implode("\n", array_filter([
                    "Product ID: {$product->id}",
                    "Name: {$product->name}",
                    filled($product->description) ? "Description: {$product->description}" : null,
                    'Destination: '.$product->destination_type->value,
                ])),
                metadata: [
                    'status' => $product->status->value,
                    'destination_type' => $product->destination_type->value,
                    'public_id' => $product->public_id,
                ],
                active: $product->status === ProfileProductStatus::Published,
                locale: $profile->locale,
            ))
            ->values()
            ->all();
    }

    /** @return array<int, ProfileKnowledgeDocument> */
    private function productGuidanceDocuments(Profile $profile): array
    {
        $guidance = trim((string) $profile->product_recommendation_guidance);

        if ($guidance === '') {
            return [];
        }

        return [new ProfileKnowledgeDocument(
            key: 'product.guidance',
            sourceType: 'product_guidance',
            sourceId: (string) $profile->id,
            content: "Product recommendation guidance: {$guidance}",
            metadata: ['routing_only' => true],
            locale: $profile->locale,
        )];
    }

    /** @return array<int, string> */
    private function splitText(string $text): array
    {
        $text = trim($text);
        $length = max(500, (int) config('ai-knowledge.indexing.raw_source_chunk_characters', 2400));

        if (mb_strlen($text) <= $length) {
            return [$text];
        }

        $blocks = preg_split('/\n{2,}/u', $text) ?: [$text];
        $chunks = [];
        $current = '';

        foreach ($blocks as $block) {
            $block = trim($block);

            if ($block === '') {
                continue;
            }

            foreach ($this->splitOversizedBlock($block, $length) as $part) {
                $candidate = $current === '' ? $part : $current."\n\n".$part;

                if (mb_strlen($candidate) <= $length) {
                    $current = $candidate;

                    continue;
                }

                if ($current !== '') {
                    $chunks[] = $current;
                }

                $current = $part;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return array_values(array_filter(array_map('trim', $chunks)));
    }

    /** @return array<int, string> */
    private function splitOversizedBlock(string $block, int $length): array
    {
        if (mb_strlen($block) <= $length) {
            return [$block];
        }

        $sentences = preg_split('/(?<=[.!?])\s+(?=[\pL\pN])/u', $block) ?: [$block];
        $parts = [];
        $current = '';

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);

            if ($sentence === '') {
                continue;
            }

            if (mb_strlen($sentence) > $length) {
                if ($current !== '') {
                    $parts[] = $current;
                    $current = '';
                }

                for ($offset = 0; $offset < mb_strlen($sentence); $offset += $length) {
                    $parts[] = mb_substr($sentence, $offset, $length);
                }

                continue;
            }

            $candidate = $current === '' ? $sentence : $current.' '.$sentence;

            if (mb_strlen($candidate) <= $length) {
                $current = $candidate;
            } else {
                $parts[] = $current;
                $current = $sentence;
            }
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts;
    }

    private function encodeValue(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return trim((string) $value);
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function emptyValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
