<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\BusinessInstructionAI;

use App\Classes\BusinessInstructionAI\OpenAIBusinessInstructionAI;
use App\Models\Business;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIBusinessInstructionAITest extends TestCase
{
    public function test_it_generates_a_contextual_localized_message_and_filters_source_ids(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'message' => '¿En qué sistemas está hoy la información y qué reportes necesitas obtener?',
                    'source_chunk_ids' => [21, 999],
                ])]]],
                'usage' => ['prompt_tokens' => 140, 'completion_tokens' => 30],
            ]),
        ]);
        $business = new Business(['name' => 'Bigmelo Labs', 'description' => 'Soluciones de datos.']);
        $business->id = 9;

        $result = (new OpenAIBusinessInstructionAI)->generate(
            business: $business,
            instruction: 'Pregunta qué información concreta falta y da un ejemplo relacionado.',
            locale: 'es',
            conversationContext: [
                ['role' => 'assistant', 'content' => 'Cuéntanos qué necesitas.'],
                ['role' => 'visitor', 'content' => 'Quiero unificar información para tomar mejores decisiones.'],
            ],
            businessDescription: $business->description,
            knowledge: [[
                'chunk_id' => 21,
                'source_name' => 'Data warehouse',
                'content' => 'Integramos fuentes y construimos reportes operativos.',
            ]],
            leadData: ['project_summary' => 'Quiero unificar información para tomar mejores decisiones.'],
            requiredFields: [],
            optionalFields: [],
        );

        $this->assertTrue($result->successful());
        $this->assertSame('¿En qué sistemas está hoy la información y qué reportes necesitas obtener?', $result->message);
        $this->assertSame([21], $result->sourceChunkIds);
        $this->assertSame(140, $result->inputTokens);
        $this->assertSame(30, $result->outputTokens);
        Http::assertSent(function ($request): bool {
            $payload = json_decode((string) data_get($request->data(), 'messages.1.content'), true);

            return data_get($payload, 'locale') === 'es'
                && data_get($payload, 'instruction') === 'Pregunta qué información concreta falta y da un ejemplo relacionado.'
                && str_contains((string) data_get($request->data(), 'messages.0.content'), 'exactly one concise visitor-facing chatbot message');
        });
    }

    public function test_it_returns_an_empty_safe_result_when_the_provider_fails(): void
    {
        Http::fake(['*/chat/completions' => Http::response([], 500)]);
        $business = new Business(['name' => 'Bigmelo Labs']);
        $business->id = 9;

        $result = (new OpenAIBusinessInstructionAI)->generate(
            business: $business,
            instruction: 'Pregunta por más información.',
            locale: 'es',
            conversationContext: [],
            businessDescription: null,
            knowledge: [],
            leadData: [],
            requiredFields: [],
            optionalFields: [],
        );

        $this->assertFalse($result->successful());
        $this->assertSame('', $result->message);
    }
}
