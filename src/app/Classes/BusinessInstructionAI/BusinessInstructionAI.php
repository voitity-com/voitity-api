<?php

namespace App\Classes\BusinessInstructionAI;

use App\Models\Business;

interface BusinessInstructionAI
{
    /**
     * @param  array<int, array{role: string, content: string}>  $conversationContext
     * @param  array<int, array<string, mixed>>  $knowledge
     * @param  array<string, mixed>  $leadData
     * @param  array<int, string>  $requiredFields
     * @param  array<int, string>  $optionalFields
     */
    public function generate(
        Business $business,
        string $instruction,
        string $locale,
        array $conversationContext,
        ?string $businessDescription,
        array $knowledge,
        array $leadData,
        array $requiredFields,
        array $optionalFields,
    ): BusinessInstructionResult;
}
