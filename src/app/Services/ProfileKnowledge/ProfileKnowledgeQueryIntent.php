<?php

namespace App\Services\ProfileKnowledge;

class ProfileKnowledgeQueryIntent
{
    /**
     * @param  array<int, string>  $sourceTypes
     * @param  array<int, string>  $excludedSourceTypes
     * @param  array<int, string>  $providers
     * @param  array<int, string>  $terms
     * @param  array<int, string>  $identifiers
     */
    public function __construct(
        public readonly bool $media,
        public readonly bool $explicitMediaShow,
        public readonly bool $socialLink,
        public readonly bool $product,
        public readonly bool $productRecommendation,
        public readonly array $sourceTypes,
        public readonly array $excludedSourceTypes,
        public readonly array $providers,
        public readonly array $terms,
        public readonly array $identifiers,
    ) {}
}
