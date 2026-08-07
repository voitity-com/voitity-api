<?php

namespace App\Services\ProfileKnowledge;

use App\Enums\ProfileSourceStatus;
use App\Models\Profile;
use App\Models\ProfileSource;

class ProfileKnowledgeSourceDeduplicator
{
    /**
     * Backfill stable hashes and make the oldest source canonical. Empty
     * sources remain non-duplicate and are handled as not indexable.
     *
     * @return array<int, int> Map of duplicate source ID to canonical source ID.
     */
    public function synchronize(Profile $profile): array
    {
        $sources = $profile->sources()->with('items')->orderBy('id')->get();
        $canonicalByHash = [];
        $duplicates = [];

        foreach ($sources as $source) {
            $hash = $this->contentHash($source);
            $canonicalId = $hash !== null ? ($canonicalByHash[$hash] ?? null) : null;

            if ($hash !== null && $canonicalId === null) {
                $canonicalByHash[$hash] = (int) $source->id;
            }

            $duplicateOf = $canonicalId !== null ? (int) $canonicalId : null;
            $metadata = (array) ($source->metadata ?? []);
            $metadata['knowledge_index'] = array_filter([
                'content_available' => $hash !== null,
                'duplicate_of_source_id' => $duplicateOf,
            ], fn ($value): bool => $value !== null);

            $changes = [
                'content_hash' => $hash,
                'duplicate_of_source_id' => $duplicateOf,
                'metadata' => $metadata,
            ];

            if ($duplicateOf !== null && $source->approved_at !== null) {
                $changes['status'] = ProfileSourceStatus::Approved;
                $changes['indexed_at'] = null;
                $duplicates[(int) $source->id] = $duplicateOf;
            }

            $source->forceFill($changes)->saveQuietly();
        }

        return $duplicates;
    }

    public function normalizedContentHash(string $content): ?string
    {
        $normalized = preg_replace('/\s+/u', ' ', mb_strtolower(trim($content))) ?? '';

        return $normalized !== '' ? hash('sha256', $normalized) : null;
    }

    private function contentHash(ProfileSource $source): ?string
    {
        if (filled($source->extracted_text)) {
            return $this->normalizedContentHash((string) $source->extracted_text);
        }

        $content = $source->items
            ->sortBy('id')
            ->map(fn ($item): string => implode("\n", array_filter([
                $item->type,
                $item->title,
                $item->content,
            ])))
            ->filter(fn (string $value): bool => trim($value) !== '')
            ->implode("\n---\n");

        return $this->normalizedContentHash($content);
    }
}
