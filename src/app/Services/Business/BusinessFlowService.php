<?php

namespace App\Services\Business;

use App\Enums\BusinessFlowVersionStatus;
use App\Models\Business;
use App\Models\BusinessFlow;
use App\Models\BusinessFlowVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BusinessFlowService
{
    public function __construct(
        private readonly BusinessFlowTemplate $template,
        private readonly BusinessFlowValidator $validator,
    ) {}

    public function initialize(Business $business, ?User $actor = null): BusinessFlow
    {
        return DB::transaction(function () use ($business, $actor): BusinessFlow {
            $flow = BusinessFlow::query()->create(['business_id' => $business->id]);
            $version = $flow->versions()->create([
                'version' => 1,
                'revision' => 1,
                'status' => BusinessFlowVersionStatus::Draft,
                'created_by_user_id' => $actor?->id,
            ]);
            $this->replaceGraph($version, $this->template->graph());
            $flow->update(['draft_version_id' => $version->id]);

            return $flow->fresh(['draftVersion.nodes', 'draftVersion.edges']);
        });
    }

    /** @param array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>} $graph */
    public function saveDraft(BusinessFlow $flow, array $graph, ?User $actor = null): BusinessFlowVersion
    {
        return DB::transaction(function () use ($flow, $graph, $actor): BusinessFlowVersion {
            $draft = $flow->draftVersion;
            if (! $draft) {
                $nextVersion = ((int) $flow->versions()->max('version')) + 1;
                $draft = $flow->versions()->create([
                    'version' => $nextVersion,
                    'revision' => 1,
                    'status' => BusinessFlowVersionStatus::Draft,
                    'created_by_user_id' => $actor?->id,
                ]);
                $flow->update(['draft_version_id' => $draft->id]);
            } else {
                $draft->increment('revision');
            }
            $this->replaceGraph($draft, $graph);

            return $draft->fresh(['nodes', 'edges']);
        });
    }

    public function publish(BusinessFlow $flow, ?User $actor = null): BusinessFlowVersion
    {
        $draft = $flow->draftVersion?->load(['nodes', 'edges']);
        if (! $draft) {
            throw ValidationException::withMessages(['flow' => 'No existe un borrador para publicar.']);
        }

        $graph = $this->serializeVersion($draft);
        $validation = $this->validator->validate($graph['nodes'], $graph['edges']);
        if (! $validation['valid']) {
            throw ValidationException::withMessages(['flow' => $validation['errors']]);
        }

        return DB::transaction(function () use ($flow, $draft, $graph, $actor): BusinessFlowVersion {
            if ($flow->publishedVersion) {
                $flow->publishedVersion->update(['status' => BusinessFlowVersionStatus::Archived]);
            }
            $draft->update(['status' => BusinessFlowVersionStatus::Published, 'published_at' => now()]);

            $nextDraft = $flow->versions()->create([
                'version' => ((int) $flow->versions()->max('version')) + 1,
                'revision' => 1,
                'status' => BusinessFlowVersionStatus::Draft,
                'created_by_user_id' => $actor?->id,
            ]);
            $this->replaceGraph($nextDraft, $graph);
            $flow->update(['published_version_id' => $draft->id, 'draft_version_id' => $nextDraft->id]);

            return $draft->fresh(['nodes', 'edges']);
        });
    }

    /** @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>} */
    public function serializeVersion(BusinessFlowVersion $version): array
    {
        $version->loadMissing(['nodes', 'edges']);

        return [
            'nodes' => $version->nodes->map(fn ($node): array => [
                'key' => $node->node_key,
                'type' => $node->type->value,
                'title' => $node->title,
                'x' => $node->position_x,
                'y' => $node->position_y,
                'config' => $node->config ?? [],
            ])->values()->all(),
            'edges' => $version->edges->map(fn ($edge): array => [
                'key' => $edge->edge_key,
                'source' => $edge->source_node_key,
                'target' => $edge->target_node_key,
                'source_handle' => $edge->source_handle,
                'label' => $edge->label,
                'config' => $edge->config ?? [],
            ])->values()->all(),
        ];
    }

    /** @param array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>} $graph */
    private function replaceGraph(BusinessFlowVersion $version, array $graph): void
    {
        $version->nodes()->delete();
        $version->edges()->delete();
        foreach ($graph['nodes'] as $node) {
            $version->nodes()->create([
                'node_key' => $node['key'], 'type' => $node['type'], 'title' => $node['title'],
                'position_x' => (int) $node['x'], 'position_y' => (int) $node['y'], 'config' => $node['config'] ?? [],
            ]);
        }
        foreach ($graph['edges'] as $edge) {
            $version->edges()->create([
                'edge_key' => $edge['key'], 'source_node_key' => $edge['source'],
                'target_node_key' => $edge['target'], 'source_handle' => $edge['source_handle'] ?? null,
                'label' => $edge['label'] ?? null, 'config' => $edge['config'] ?? [],
            ]);
        }
    }
}
