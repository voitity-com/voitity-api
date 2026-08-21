<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Services\Business\BusinessFlowService;
use Illuminate\Console\Command;

class UpgradeBusinessFlowDecisionsCommand extends Command
{
    protected $signature = 'business:upgrade-flow-decisions {business : Business ID} {--publish : Publish the upgraded draft}';

    protected $description = 'Upgrade legacy technology decisions to configurable knowledge-backed yes/no decisions';

    public function handle(BusinessFlowService $flows): int
    {
        $business = Business::query()
            ->with(['flow.draftVersion.nodes', 'flow.draftVersion.edges'])
            ->findOrFail((int) $this->argument('business'));
        $flow = $business->flow;
        $draft = $flow?->draftVersion;
        if (! $flow || ! $draft) {
            $this->error('The Business does not have an editable flow draft.');

            return self::FAILURE;
        }

        $graph = $flows->serializeVersion($draft);
        $upgradedKeys = [];
        foreach ($graph['nodes'] as &$node) {
            if ($node['type'] !== 'decision' || ($node['config']['mode'] ?? null) !== 'technology_interest') {
                continue;
            }
            $questionEs = trim((string) $node['title']);
            $node['title'] = 'Calificar problema con fuentes';
            $node['config'] = [
                ...$node['config'],
                'mode' => 'knowledge_yes_no',
                'question' => $questionEs,
                'questions' => [
                    'es' => $questionEs,
                    'en' => 'Does the described problem match a product, service, or problem that this business can solve?',
                ],
                'use_business_description' => true,
                'use_sources' => true,
                'branches' => ['yes', 'no'],
            ];
            $upgradedKeys[] = $node['key'];
        }
        unset($node);

        foreach ($graph['edges'] as &$edge) {
            if (! in_array($edge['source'], $upgradedKeys, true)) {
                continue;
            }
            if ($edge['source_handle'] === 'technology') {
                $edge['source_handle'] = 'yes';
                $edge['label'] = 'Sí';
            } elseif ($edge['source_handle'] === 'other') {
                $edge['source_handle'] = 'no';
                $edge['label'] = 'No';
            }
        }
        unset($edge);

        if ($upgradedKeys === []) {
            $this->info('No legacy technology decisions required an upgrade.');

            return self::SUCCESS;
        }

        $saved = $flows->saveDraft($flow, $graph);
        $this->info('Upgraded draft version '.$saved->version.' nodes: '.implode(', ', $upgradedKeys));

        if ($this->option('publish')) {
            $published = $flows->publish($flow->fresh());
            $this->info('Published upgraded flow version '.$published->version.'.');
        }

        return self::SUCCESS;
    }
}
