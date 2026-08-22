<?php

namespace App\Classes\BusinessInstructionAI;

use App\Models\Business;

class LocalBusinessInstructionAI implements BusinessInstructionAI
{
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
    ): BusinessInstructionResult {
        return new BusinessInstructionResult(
            message: '',
            sourceChunkIds: [],
            provider: 'local',
            model: 'manual-fallback-v1',
        );
    }
}
