<?php

namespace App\Classes\BusinessProblemAI;

use App\Models\Business;

interface BusinessProblemAI
{
    /**
     * @param  array<int, array{id: int, node_key: string|null, role: string, content: string}>  $conversation
     */
    public function summarize(Business $business, array $conversation, string $locale): BusinessProblemResult;
}
