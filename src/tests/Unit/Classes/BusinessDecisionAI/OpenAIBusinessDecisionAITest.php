<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\BusinessDecisionAI;

use App\Classes\BusinessDecisionAI\BusinessDecisionOutcome;
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
                    'decision' => 'yes',
                    'confidence' => 0.93,
                    'reason' => 'The indexed source includes decision quality and analytics.',
                    'source_chunk_ids' => [41, 999],
                ])]]],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 24],
            ]),
        ]);
        $business = new Business(['name' => 'Bigmelo Labs', 'description' => 'Software, IA y datos.']);
        $business->id = 7;

        $result = (new OpenAIBusinessDecisionAI)->evaluate(
            business: $business,
            question: '¿Podemos solucionar este problema?',
            lastAssistantMessage: 'Cuéntanos qué problema quieres resolver.',
            lastVisitorMessage: 'Necesito tomar mejores decisiones.',
            conversationContext: [
                ['role' => 'assistant', 'content' => 'Cuéntanos qué problema quieres resolver.'],
                ['role' => 'visitor', 'content' => 'Necesito tomar mejores decisiones.'],
            ],
            problem: 'Necesito tomar mejores decisiones.',
            businessDescription: $business->description,
            knowledge: [[
                'chunk_id' => 41,
                'source_name' => 'Problemas',
                'content' => 'Mejorar la calidad de las decisiones con analítica.',
            ]],
            locale: 'es',
        );

        $this->assertSame(BusinessDecisionOutcome::Yes, $result->outcome);
        $this->assertSame(0.93, $result->confidence);
        $this->assertSame([41], $result->sourceChunkIds);
        $this->assertSame(120, $result->inputTokens);
        $this->assertSame(24, $result->outputTokens);
        Http::assertSent(fn ($request): bool => data_get($request->data(), 'response_format.type') === 'json_schema'
            && str_contains((string) data_get($request->data(), 'messages.0.content'), 'Never use Business description or knowledge to supply problem details')
            && str_contains((string) data_get($request->data(), 'messages.1.content'), 'last_visitor_message')
            && str_contains((string) data_get($request->data(), 'messages.1.content'), 'Mejorar la calidad'));
    }

    public function test_it_uses_ai_to_interpret_a_colloquial_affirmation_in_context(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'decision' => 'yes',
                    'confidence' => 0.98,
                    'reason' => 'The visitor accepted the offer in colloquial Spanish.',
                    'source_chunk_ids' => [],
                ])]]],
            ]),
        ]);
        $business = new Business(['name' => 'Bigmelo Labs', 'description' => 'Software, IA y datos.']);
        $business->id = 7;

        $result = (new OpenAIBusinessDecisionAI)->evaluate(
            business: $business,
            question: '¿El usuario desea conocer nuestras tarifas?',
            lastAssistantMessage: '¿Deseas conocer nuestras tarifas?',
            lastVisitorMessage: 'De una',
            conversationContext: [
                ['role' => 'assistant', 'content' => '¿Deseas conocer nuestras tarifas?'],
                ['role' => 'visitor', 'content' => 'De una'],
            ],
            problem: null,
            businessDescription: null,
            knowledge: [],
            locale: 'es',
        );

        $this->assertSame(BusinessDecisionOutcome::Yes, $result->outcome);
        Http::assertSent(function ($request): bool {
            $payload = json_decode((string) data_get($request->data(), 'messages.1.content'), true);

            return data_get($payload, 'last_assistant_message') === '¿Deseas conocer nuestras tarifas?'
                && data_get($payload, 'last_visitor_message') === 'De una'
                && data_get($request->data(), 'response_format.json_schema.schema.properties.decision.enum') === ['yes', 'no', 'unclear'];
        });
    }

    public function test_invalid_provider_response_requires_clarification_instead_of_selecting_no(): void
    {
        Http::fake(['*/chat/completions' => Http::response(['choices' => []], 502)]);
        $business = new Business(['name' => 'Bigmelo Labs']);
        $business->id = 7;

        $result = (new OpenAIBusinessDecisionAI)->evaluate(
            business: $business,
            question: '¿Deseas continuar?',
            lastAssistantMessage: '¿Deseas continuar?',
            lastVisitorMessage: 'No sé',
            conversationContext: [],
            problem: null,
            businessDescription: null,
            knowledge: [],
            locale: 'es',
        );

        $this->assertSame(BusinessDecisionOutcome::Unclear, $result->outcome);
        $this->assertSame(0.0, $result->confidence);
    }
}
