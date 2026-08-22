<?php

namespace App\Classes\BusinessDecisionAI;

use App\Classes\BusinessFlowAI\BusinessFlowAI;
use App\Models\Business;
use App\Services\Business\BusinessUsageRecorder;

class LocalBusinessDecisionAI implements BusinessDecisionAI
{
    public function __construct(
        private readonly BusinessFlowAI $flowAI,
        private readonly BusinessUsageRecorder $usage,
        private readonly BusinessDecisionEvidencePolicy $evidencePolicy,
    ) {}

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
    ): BusinessDecisionResult {
        if ($this->evidencePolicy->asksWhetherVisitorProblemIsSufficient($question)
            && ! $this->evidencePolicy->visitorProblemHasMinimumDetail($problem)) {
            return $this->evidencePolicy->insufficientResult();
        }

        $visitorContext = collect($conversationContext)
            ->filter(fn (mixed $message): bool => is_array($message) && ($message['role'] ?? null) === 'visitor')
            ->pluck('content')
            ->filter(fn (mixed $content): bool => is_string($content))
            ->implode("\n");
        $classification = $this->flowAI->classifyTechnology(trim(($problem ?: '')."\n".$visitorContext));
        $knowledgeScore = (float) collect($knowledge)->max('score');
        $answer = ($classification->data['branch'] ?? 'other') === 'technology'
            || ($knowledge !== [] && $knowledgeScore >= (float) config('business-ai.knowledge.minimum_score', 0.32));
        $reason = $answer && $knowledge !== []
            ? 'Relevant indexed Business knowledge supports the requested need.'
            : ($answer ? 'The request contains a supported technology need.' : 'No relevant supported need was found.');

        return new BusinessDecisionResult(
            outcome: $answer ? BusinessDecisionOutcome::Yes : BusinessDecisionOutcome::No,
            confidence: $answer ? 0.86 : 0.64,
            reason: $reason,
            sourceChunkIds: array_values(array_map('intval', array_column($knowledge, 'chunk_id'))),
            provider: 'local',
            model: 'heuristic-rag-v1',
            inputTokens: $this->usage->estimateTokens($question.$lastAssistantMessage.$lastVisitorMessage.$visitorContext.($problem ?? '').($businessDescription ?? '')),
            outputTokens: $this->usage->estimateTokens($reason),
        );
    }
}
