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
        $this->assertStringContainsString('technology', implode(' ', $result['errors']));
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
}
