<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\BusinessDecisionAI;

use App\Classes\BusinessDecisionAI\BusinessDecisionEvidencePolicy;
use PHPUnit\Framework\TestCase;

class BusinessDecisionEvidencePolicyTest extends TestCase
{
    public function test_it_recognizes_problem_sufficiency_questions_in_both_languages(): void
    {
        $policy = new BusinessDecisionEvidencePolicy;

        $this->assertTrue($policy->asksWhetherVisitorProblemIsSufficient(
            '¿El problema está suficientemente descrito y tiene lo mínimo para plantear una solución?'
        ));
        $this->assertTrue($policy->asksWhetherVisitorProblemIsSufficient(
            'Is the problem sufficiently described with enough detail to propose a solution?'
        ));
        $this->assertFalse($policy->asksWhetherVisitorProblemIsSufficient(
            '¿Este problema corresponde a un servicio ofrecido por el negocio?'
        ));
    }

    public function test_short_generic_goals_are_insufficient_but_concrete_descriptions_reach_ai_evaluation(): void
    {
        $policy = new BusinessDecisionEvidencePolicy;

        $this->assertFalse($policy->visitorProblemHasMinimumDetail('Necesito tomar mejores decisiones.'));
        $this->assertFalse($policy->visitorProblemHasMinimumDetail('Quiero un chatbot.'));
        $this->assertTrue($policy->visitorProblemHasMinimumDetail(
            'Nuestro equipo procesa facturas manualmente y queremos automatizar la extracción para reducir errores y tiempos.'
        ));
    }
}
