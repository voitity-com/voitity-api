<?php

namespace App\Classes\ConversationInsights;

use App\Enums\ConversationCategory;

class ConversationClassification
{
    public function __construct(
        public readonly ConversationCategory $primaryCategory,
        public readonly array $secondaryCategories,
        public readonly float $confidence,
        public readonly string $summary,
        public readonly array $evidenceMessageIds,
        public readonly string $model,
    ) {}
}
