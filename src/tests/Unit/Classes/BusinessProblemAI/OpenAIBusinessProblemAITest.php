<?php

declare(strict_types=1);

namespace Tests\Unit\Classes\BusinessProblemAI;

use App\Classes\BusinessProblemAI\BusinessProblemAI;
use App\Classes\BusinessProblemAI\OpenAIBusinessProblemAI;
use App\Models\Business;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIBusinessProblemAITest extends TestCase
{
    public function test_it_synthesizes_the_problem_from_the_complete_role_aware_conversation(): void
    {
        Http::fake([
            '*/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => json_encode([
                    'project_summary' => 'El cliente necesita unificar información de varias fuentes, automatizar reportes y disponer de indicadores para mejorar la toma de decisiones.',
                    'evidence_message_ids' => [11, 12, 13, 999],
                    'confidence' => 0.96,
                ])]]],
                'usage' => ['prompt_tokens' => 180, 'completion_tokens' => 38],
            ]),
        ]);
        $business = new Business(['name' => 'Bigmelo Labs']);
        $business->id = 7;
        $conversation = [
            ['id' => 10, 'node_key' => 'welcome', 'role' => 'assistant', 'content' => '¿Qué problema quieres resolver?'],
            ['id' => 11, 'node_key' => 'welcome', 'role' => 'visitor', 'content' => 'Quiero tomar mejores decisiones en mi negocio.'],
            ['id' => 12, 'node_key' => 'clarify', 'role' => 'assistant', 'content' => '¿Necesitas unificar información, automatizar reportes y construir indicadores?'],
            ['id' => 13, 'node_key' => 'clarify', 'role' => 'visitor', 'content' => 'Sí, necesito exactamente eso.'],
        ];

        $result = (new OpenAIBusinessProblemAI)->summarize($business, $conversation, 'es');

        $this->assertTrue($result->successful());
        $this->assertStringContainsString('unificar información', $result->summary);
        $this->assertSame([11, 12, 13], $result->evidenceMessageIds);
        $this->assertSame(0.96, $result->confidence);
        $this->assertSame(180, $result->inputTokens);
        $this->assertSame(38, $result->outputTokens);
        Http::assertSent(function ($request) use ($conversation): bool {
            $payload = json_decode((string) data_get($request->data(), 'messages.1.content'), true);

            return data_get($request->data(), 'response_format.type') === 'json_schema'
                && data_get($payload, 'conversation') === $conversation
                && str_contains((string) data_get($request->data(), 'messages.0.content'), 'complete Business chatbot transcript')
                && str_contains((string) data_get($request->data(), 'messages.0.content'), 'chronological context');
        });
    }

    public function test_invalid_provider_response_returns_a_safe_empty_result(): void
    {
        Http::fake(['*/chat/completions' => Http::response(['choices' => []], 502)]);
        $business = new Business(['name' => 'Bigmelo Labs']);
        $business->id = 7;

        $result = (new OpenAIBusinessProblemAI)->summarize($business, [
            ['id' => 1, 'node_key' => 'welcome', 'role' => 'visitor', 'content' => 'Necesito automatizar reportes.'],
        ], 'es');

        $this->assertFalse($result->successful());
        $this->assertSame('', $result->summary);
        $this->assertSame([], $result->evidenceMessageIds);
        $this->assertSame(0.0, $result->confidence);
    }

    public function test_container_resolves_the_configured_openai_driver(): void
    {
        Config::set('business-ai.problem.driver', 'openai');

        $this->assertInstanceOf(OpenAIBusinessProblemAI::class, app(BusinessProblemAI::class));
    }
}
