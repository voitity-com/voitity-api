<?php

namespace App\Classes\BusinessProblemAI;

use App\Models\Business;
use App\Services\Business\BusinessUsageRecorder;

class LocalBusinessProblemAI implements BusinessProblemAI
{
    public function __construct(private readonly BusinessUsageRecorder $usage) {}

    public function summarize(Business $business, array $conversation, string $locale): BusinessProblemResult
    {
        $candidate = collect($conversation)
            ->filter(fn (array $message): bool => $message['role'] === 'visitor')
            ->map(fn (array $message): array => [
                'id' => $message['id'],
                'content' => trim($message['content']),
            ])
            ->filter(fn (array $message): bool => mb_strlen($message['content']) >= 20)
            ->sortByDesc(fn (array $message): int => mb_strlen($message['content']))
            ->first();
        $summary = is_array($candidate) ? $candidate['content'] : '';
        $input = json_encode($conversation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';

        return new BusinessProblemResult(
            summary: $summary,
            evidenceMessageIds: is_array($candidate) ? [(int) $candidate['id']] : [],
            confidence: $summary === '' ? 0 : 0.5,
            provider: 'local',
            model: 'conservative-transcript-v1',
            inputTokens: $this->usage->estimateTokens($input),
            outputTokens: $this->usage->estimateTokens($summary),
        );
    }
}
