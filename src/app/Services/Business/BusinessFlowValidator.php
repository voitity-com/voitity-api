<?php

namespace App\Services\Business;

use App\Enums\BusinessFlowNodeType;

class BusinessFlowValidator
{
    private const ACTIONS = ['capture_problem', 'extract_fields', 'analyze_solution', 'finalize_lead'];

    private const DECISION_MODES = ['technology_interest', 'required_fields_complete'];

    /** @param array<int, array<string, mixed>> $nodes @param array<int, array<string, mixed>> $edges */
    public function validate(array $nodes, array $edges): array
    {
        $errors = [];
        $keys = [];
        $startNodes = [];

        foreach ($nodes as $index => $node) {
            $key = trim((string) ($node['key'] ?? ''));
            $type = (string) ($node['type'] ?? '');
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];

            if ($key === '') {
                $errors[] = "El nodo #{$index} no tiene identificador.";

                continue;
            }
            if (isset($keys[$key])) {
                $errors[] = "El identificador de nodo {$key} está duplicado.";
            }
            $keys[$key] = true;

            if (BusinessFlowNodeType::tryFrom($type) === null) {
                $errors[] = "El nodo {$key} tiene un tipo inválido.";
            }
            if (($config['start'] ?? false) === true) {
                $startNodes[] = $key;
            }
            if ($type === BusinessFlowNodeType::Instruction->value && trim((string) ($config['message'] ?? '')) === '') {
                $errors[] = "La indicación {$key} no tiene mensaje.";
            }
            if ($type === BusinessFlowNodeType::Decision->value) {
                $mode = trim((string) ($config['mode'] ?? ''));
                if ($mode === '') {
                    $errors[] = "La decisión {$key} no tiene modo de evaluación.";
                } elseif (! in_array($mode, self::DECISION_MODES, true)) {
                    $errors[] = "La decisión {$key} usa el modo no soportado {$mode}.";
                }
            }
            if ($type === BusinessFlowNodeType::Action->value) {
                $action = trim((string) ($config['action'] ?? ''));
                if ($action === '') {
                    $errors[] = "La acción {$key} no tiene una acción configurada.";
                } elseif (! in_array($action, self::ACTIONS, true)) {
                    $errors[] = "La acción {$key} usa la operación no soportada {$action}.";
                }
            }
        }

        if (count($startNodes) !== 1) {
            $errors[] = 'El flow debe tener exactamente un nodo inicial.';
        }

        $outgoing = [];
        foreach ($edges as $index => $edge) {
            $source = (string) ($edge['source'] ?? '');
            $target = (string) ($edge['target'] ?? '');
            if (! isset($keys[$source]) || ! isset($keys[$target])) {
                $errors[] = "La conexión #{$index} referencia un nodo inexistente.";

                continue;
            }
            $outgoing[$source][] = $edge;
        }

        foreach ($nodes as $node) {
            $key = (string) ($node['key'] ?? '');
            $type = (string) ($node['type'] ?? '');
            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $connections = $outgoing[$key] ?? [];

            if ($type === BusinessFlowNodeType::Decision->value) {
                foreach (($config['branches'] ?? []) as $branch) {
                    $hasBranch = collect($connections)->contains(
                        fn (array $edge): bool => ($edge['source_handle'] ?? null) === $branch
                    );
                    if (! $hasBranch) {
                        $errors[] = "La decisión {$key} no tiene conexión para la rama {$branch}.";
                    }
                }
            }

            if ($connections === [] && ! ($type === BusinessFlowNodeType::Action->value && ($config['action'] ?? null) === 'finalize_lead')) {
                $errors[] = "El nodo {$key} no tiene una salida.";
            }
        }

        if ($startNodes !== []) {
            $reachable = $this->reachableFrom($startNodes[0], $outgoing);
            foreach (array_keys($keys) as $key) {
                if (! isset($reachable[$key])) {
                    $errors[] = "El nodo {$key} no es alcanzable desde el inicio.";
                }
            }
        }

        return ['valid' => $errors === [], 'errors' => array_values(array_unique($errors))];
    }

    /** @param array<string, array<int, array<string, mixed>>> $outgoing @return array<string, bool> */
    private function reachableFrom(string $start, array $outgoing): array
    {
        $seen = [];
        $pending = [$start];
        while ($pending !== []) {
            $key = array_pop($pending);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            foreach ($outgoing[$key] ?? [] as $edge) {
                $pending[] = (string) $edge['target'];
            }
        }

        return $seen;
    }
}
