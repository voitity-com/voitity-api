<?php

namespace App\Classes\BusinessDecisionAI;

use App\Models\Business;

interface BusinessDecisionAI
{
    /** @param array<int, array<string, mixed>> $knowledge */
    public function evaluate(
        Business $business,
        string $question,
        string $visitorContext,
        ?string $problem,
        ?string $businessDescription,
        array $knowledge,
        string $locale,
    ): BusinessDecisionResult;
}
