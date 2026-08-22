<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Business;

use App\Services\Business\BusinessFlowTemplate;
use App\Services\Business\BusinessFlowValidator;
use PHPUnit\Framework\TestCase;

class BusinessFlowValidatorTest extends TestCase
{
    public function test_default_business_flow_is_valid(): void
    {
        $graph = (new BusinessFlowTemplate)->graph();

        $result = (new BusinessFlowValidator)->validate($graph['nodes'], $graph['edges']);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_it_detects_missing_decision_branches_and_unreachable_nodes(): void
    {
        $graph = (new BusinessFlowTemplate)->graph();
        $graph['edges'] = array_values(array_filter(
            $graph['edges'],
            fn (array $edge): bool => $edge['key'] !== 'qualify-capture',
        ));

        $result = (new BusinessFlowValidator)->validate($graph['nodes'], $graph['edges']);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('yes', implode(' ', $result['errors']));
    }

    public function test_instruction_can_use_localized_messages_without_the_legacy_message(): void
    {
        $graph = (new BusinessFlowTemplate)->graph();
        unset($graph['nodes'][0]['config']['message']);

        $result = (new BusinessFlowValidator)->validate($graph['nodes'], $graph['edges']);

        $this->assertTrue($result['valid']);
    }

    public function test_terminal_instruction_is_valid_without_an_outgoing_connection(): void
    {
        $nodes = [[
            'key' => 'goodbye',
            'type' => 'instruction',
            'title' => 'Despedida',
            'x' => 0,
            'y' => 0,
            'config' => [
                'start' => true,
                'message' => 'Gracias por contactarnos.',
                'wait_for_input' => false,
                'finish_chat' => true,
            ],
        ]];

        $result = (new BusinessFlowValidator)->validate($nodes, []);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function test_terminal_instruction_rejects_input_waiting_and_outgoing_connections(): void
    {
        $nodes = [
            [
                'key' => 'goodbye',
                'type' => 'instruction',
                'title' => 'Despedida',
                'x' => 0,
                'y' => 0,
                'config' => [
                    'start' => true,
                    'message' => 'Gracias por contactarnos.',
                    'wait_for_input' => true,
                    'finish_chat' => true,
                ],
            ],
            [
                'key' => 'unused',
                'type' => 'instruction',
                'title' => 'No debe ejecutarse',
                'x' => 300,
                'y' => 0,
                'config' => [
                    'message' => 'Mensaje posterior.',
                    'wait_for_input' => false,
                    'finish_chat' => true,
                ],
            ],
        ];
        $edges = [[
            'key' => 'invalid-terminal-output',
            'source' => 'goodbye',
            'target' => 'unused',
            'source_handle' => null,
            'label' => null,
            'config' => [],
        ]];

        $result = (new BusinessFlowValidator)->validate($nodes, $edges);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('no puede esperar una respuesta', implode(' ', $result['errors']));
        $this->assertStringContainsString('no debe tener conexiones de salida', implode(' ', $result['errors']));
    }

    public function test_it_rejects_unsupported_decision_modes_and_actions_before_publish(): void
    {
        $graph = (new BusinessFlowTemplate)->graph();
        $decision = array_search('qualify', array_column($graph['nodes'], 'key'), true);
        $action = array_search('analyze_solution', array_column($graph['nodes'], 'key'), true);
        $graph['nodes'][$decision]['config']['mode'] = 'execute_arbitrary_prompt';
        $graph['nodes'][$action]['config']['action'] = 'send_unconfigured_webhook';

        $result = (new BusinessFlowValidator)->validate($graph['nodes'], $graph['edges']);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('execute_arbitrary_prompt', implode(' ', $result['errors']));
        $this->assertStringContainsString('send_unconfigured_webhook', implode(' ', $result['errors']));
    }

    public function test_knowledge_decision_requires_a_question_and_fixed_yes_no_branches(): void
    {
        $graph = (new BusinessFlowTemplate)->graph();
        $decision = array_search('qualify', array_column($graph['nodes'], 'key'), true);
        $graph['nodes'][$decision]['config']['question'] = '';
        $graph['nodes'][$decision]['config']['questions'] = ['es' => '', 'en' => ''];
        $graph['nodes'][$decision]['config']['branches'] = ['maybe', 'later'];

        $result = (new BusinessFlowValidator)->validate($graph['nodes'], $graph['edges']);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('pregunta', implode(' ', $result['errors']));
        $this->assertStringContainsString('yes y no', implode(' ', $result['errors']));
    }

    public function test_decision_requires_exactly_one_connection_per_configured_branch(): void
    {
        $graph = (new BusinessFlowTemplate)->graph();
        $yesEdge = collect($graph['edges'])->firstWhere('key', 'qualify-capture');
        $graph['edges'][] = [...$yesEdge, 'key' => 'qualify-capture-duplicate'];
        $graph['edges'][] = [
            ...$yesEdge,
            'key' => 'qualify-unknown',
            'source_handle' => 'unknown',
        ];

        $result = (new BusinessFlowValidator)->validate($graph['nodes'], $graph['edges']);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('más de una conexión para la rama yes', implode(' ', $result['errors']));
        $this->assertStringContainsString('rama no configurada', implode(' ', $result['errors']));
    }
}
