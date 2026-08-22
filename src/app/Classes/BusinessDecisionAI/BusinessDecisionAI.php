<?php

namespace App\Classes\BusinessDecisionAI;

use App\Models\Business;

interface BusinessDecisionAI
{
    /**
     * @param  array<int, array{role: string, content: string}>  $conversationContext
     * @param  array<int, array<string, mixed>>  $knowledge
     */
    public function evaluate(
        Business $business,
        string $question,
        string $lastAssistantMessage,
        string $lastVisitorMessage,
        array $conversationContext,
        ?string $problem,
        ?string $businessDescription,
        array $knowledge,
        string $locale,
    ): BusinessDecisionResult;
}
