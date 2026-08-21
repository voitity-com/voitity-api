<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\BusinessDecisionAI;

use App\Classes\BusinessDecisionAI\BusinessDecisionEvidencePolicy;
use App\Classes\BusinessDecisionAI\OpenAIBusinessDecisionAI;
use App\Models\Business;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIBusinessDecisionAITest extends TestCase
{
    public function test_it_returns_a_strict_grounded_yes_no_decision(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'answer' => true,
                    'confidence' => 0.93,
                    'reason' => 'The indexed source includes decision quality and analytics.',
                    'source_chunk_ids' => [41, 999],
                ])]]],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 24],
            ]),
        ]);
        $business = new Business(['name' => 'Bigmelo Labs', 'description' => 'Software, IA y datos.']);
        $business->id = 7;

        $result = (new OpenAIBusinessDecisionAI(new BusinessDecisionEvidencePolicy))->evaluate(
            business: $business,
            question: '¿Podemos solucionar este problema?',
            visitorContext: 'Necesito tomar mejores decisiones.',
            problem: 'Necesito tomar mejores decisiones.',
            businessDescription: $business->description,
            knowledge: [[
                'chunk_id' => 41,
                'source_name' => 'Problemas',
                'content' => 'Mejorar la calidad de las decisiones con analítica.',
            ]],
            locale: 'es',
        );

        $this->assertTrue($result->answer);
        $this->assertSame(0.93, $result->confidence);
        $this->assertSame([41], $result->sourceChunkIds);
        $this->assertSame(120, $result->inputTokens);
        $this->assertSame(24, $result->outputTokens);
        Http::assertSent(fn ($request): bool => data_get($request->data(), 'response_format.type') === 'json_schema'
            && str_contains((string) data_get($request->data(), 'messages.0.content'), 'Never use Business description or knowledge to supply problem details')
            && str_contains((string) data_get($request->data(), 'messages.1.content'), 'Mejorar la calidad'));
    }

    public function test_it_rejects_a_short_generic_problem_before_sources_or_openai_can_complete_it(): void
    {
        Http::fake();
        $business = new Business(['name' => 'Bigmelo Labs', 'description' => 'Software, IA y datos.']);
        $business->id = 7;

        $result = (new OpenAIBusinessDecisionAI(new BusinessDecisionEvidencePolicy))->evaluate(
            business: $business,
            question: '¿El problema está suficientemente descrito, tiene lo mínimo para plantear una posible solución?',
            visitorContext: 'Proyecto o problema: Necesito tomar mejores decisiones.',
            problem: 'Necesito tomar mejores decisiones.',
            businessDescription: $business->description,
            knowledge: [[
                'chunk_id' => 41,
                'source_name' => 'Problemas',
                'content' => 'Mejorar la calidad de las decisiones con analítica.',
            ]],
            locale: 'es',
        );

        $this->assertFalse($result->answer);
        $this->assertSame(1.0, $result->confidence);
        $this->assertSame([], $result->sourceChunkIds);
        $this->assertSame('policy', $result->provider);
        Http::assertNothingSent();
    }
}
