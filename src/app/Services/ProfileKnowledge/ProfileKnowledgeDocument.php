<?php

namespace App\Services\ProfileKnowledge;

class ProfileKnowledgeDocument
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $key,
        public readonly string $sourceType,
        public readonly ?string $sourceId,
        public readonly string $content,
        public readonly array $metadata = [],
        public readonly bool $active = true,
        public readonly string $visibility = 'public',
        public readonly ?string $locale = null,
    ) {}

    public function contentHash(): string
    {
        return hash('sha256', $this->content);
    }
}
