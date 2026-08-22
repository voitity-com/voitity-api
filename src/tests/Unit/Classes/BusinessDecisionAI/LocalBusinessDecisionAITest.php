<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\BusinessDecisionAI;

use App\Classes\BusinessDecisionAI\BusinessDecisionEvidencePolicy;
use App\Classes\BusinessDecisionAI\BusinessDecisionOutcome;
use App\Classes\BusinessDecisionAI\LocalBusinessDecisionAI;
use App\Classes\BusinessFlowAI\BusinessFlowAI;
use App\Models\Business;
use App\Services\Business\BusinessUsageRecorder;
use PHPUnit\Framework\TestCase;

class LocalBusinessDecisionAITest extends TestCase
{
    public function test_relevant_business_knowledge_cannot_complete_a_vague_visitor_problem(): void
    {
        $decision = new LocalBusinessDecisionAI(
            $this->createMock(BusinessFlowAI::class),
            $this->createMock(BusinessUsageRecorder::class),
            new BusinessDecisionEvidencePolicy,
        );
        $business = new Business(['name' => 'Bigmelo Labs', 'description' => 'Software, IA y datos.']);

        $result = $decision->evaluate(
            business: $business,
            question: '¿El problema está suficientemente descrito y tiene lo mínimo para plantear una solución?',
            lastAssistantMessage: 'Describe tu problema.',
            lastVisitorMessage: 'Necesito tomar mejores decisiones.',
            conversationContext: [['role' => 'visitor', 'content' => 'Proyecto o problema: Necesito tomar mejores decisiones.']],
            problem: 'Necesito tomar mejores decisiones.',
            businessDescription: $business->description,
            knowledge: [[
                'chunk_id' => 41,
                'source_name' => 'Problemas',
                'content' => 'Mejorar la calidad de las decisiones con analítica.',
                'score' => 0.99,
            ]],
            locale: 'es',
        );

        $this->assertSame(BusinessDecisionOutcome::No, $result->outcome);
        $this->assertSame('policy', $result->provider);
        $this->assertSame([], $result->sourceChunkIds);
    }
}
