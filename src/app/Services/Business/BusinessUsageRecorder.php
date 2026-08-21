<?php

namespace App\Services\Business;

use App\Models\BusinessUsageEvent;

class BusinessUsageRecorder
{
    public function estimateTokens(string $text): int
    {
        return max(0, (int) ceil(mb_strlen($text) / 4));
    }

    /** @param array<string, mixed> $attributes */
    public function record(array $attributes): BusinessUsageEvent
    {
        $input = (int) ($attributes['input_tokens'] ?? 0);
        $output = (int) ($attributes['output_tokens'] ?? 0);

        return BusinessUsageEvent::query()->create([
            ...$attributes,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => (int) ($attributes['total_tokens'] ?? ($input + $output)),
            'occurred_at' => $attributes['occurred_at'] ?? now(),
        ]);
    }
}
