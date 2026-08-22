<?php

namespace App\Classes\BusinessDecisionAI;

final class BusinessDecisionEvidencePolicy
{
    private const MINIMUM_PROBLEM_WORDS = 8;

    public function asksWhetherVisitorProblemIsSufficient(string $question): bool
    {
        $normalized = mb_strtolower($question);
        $mentionsProblem = collect(['problema', 'problem', 'necesidad', 'need', 'descrit', 'describ', 'detail'])
            ->contains(fn (string $term): bool => str_contains($normalized, $term));
        $asksForSufficiency = collect(['suficient', 'sufficient', 'mínim', 'minim', 'enough', 'complet'])
            ->contains(fn (string $term): bool => str_contains($normalized, $term));

        return $mentionsProblem && $asksForSufficiency;
    }

    public function visitorProblemHasMinimumDetail(?string $problem): bool
    {
        $problem = trim((string) $problem);
        if ($problem === '') {
            return false;
        }

        $words = preg_split('/\s+/u', (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $problem)) ?: [];
        $words = array_values(array_filter($words, fn (string $word): bool => $word !== ''));

        return count($words) >= self::MINIMUM_PROBLEM_WORDS;
    }

    public function insufficientResult(): BusinessDecisionResult
    {
        return new BusinessDecisionResult(
            outcome: BusinessDecisionOutcome::No,
            confidence: 1,
            reason: 'The visitor supplied only a short or missing problem statement; Business knowledge cannot replace visitor-provided details.',
            sourceChunkIds: [],
            provider: 'policy',
            model: 'visitor-evidence-v1',
        );
    }
}
