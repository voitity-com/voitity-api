<?php

namespace App\Services\Business;

use App\Enums\BusinessFlowNodeType;

class BusinessFlowValidator
{
    private const ACTIONS = ['capture_problem', 'extract_fields', 'analyze_solution', 'finalize_lead'];

    private const DECISION_MODES = ['knowledge_yes_no', 'required_fields_complete', 'technology_interest'];

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
            if ($type === BusinessFlowNodeType::Instruction->value) {
                $messages = is_array($config['messages'] ?? null) ? $config['messages'] : [];
                $hasMessage = collect([$config['message'] ?? null, $messages['es'] ?? null, $messages['en'] ?? null])
                    ->contains(fn (mixed $message): bool => is_string($message) && trim($message) !== '');
                if (! $hasMessage) {
                    $errors[] = "La indicación {$key} no tiene mensaje.";
                }
                if (array_key_exists('finish_chat', $config) && ! is_bool($config['finish_chat'])) {
                    $errors[] = "La propiedad finish_chat de la indicación {$key} debe ser booleana.";
                }
                if (($config['finish_chat'] ?? false) === true && ($config['wait_for_input'] ?? false) === true) {
                    $errors[] = "La indicación final {$key} no puede esperar una respuesta del visitante.";
                }
            }
            if ($type === BusinessFlowNodeType::Decision->value) {
                $mode = trim((string) ($config['mode'] ?? ''));
                if ($mode === '') {
                    $errors[] = "La decisión {$key} no tiene modo de evaluación.";
                } elseif (! in_array($mode, self::DECISION_MODES, true)) {
                    $errors[] = "La decisión {$key} usa el modo no soportado {$mode}.";
                }
                if ($mode === 'knowledge_yes_no') {
                    $questions = is_array($config['questions'] ?? null) ? $config['questions'] : [];
                    $hasQuestion = collect([$config['question'] ?? null, $questions['es'] ?? null, $questions['en'] ?? null])
                        ->contains(fn (mixed $question): bool => is_string($question) && trim($question) !== '');
                    if (! $hasQuestion) {
                        $errors[] = "La decisión {$key} no tiene una pregunta para evaluar.";
                    }
                    if (array_values($config['branches'] ?? []) !== ['yes', 'no']) {
                        $errors[] = "La decisión {$key} debe usar exactamente las ramas yes y no.";
                    }
                }
                if ($mode === 'required_fields_complete'
                    && array_values($config['branches'] ?? []) !== ['complete', 'incomplete']) {
                    $errors[] = "La decisión {$key} debe usar exactamente las ramas complete e incomplete.";
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
                $branches = array_values($config['branches'] ?? []);
                foreach ($branches as $branch) {
                    $branchConnections = collect($connections)->filter(
                        fn (array $edge): bool => ($edge['source_handle'] ?? null) === $branch
                    );
                    if ($branchConnections->isEmpty()) {
                        $errors[] = "La decisión {$key} no tiene conexión para la rama {$branch}.";
                    } elseif ($branchConnections->count() > 1) {
                        $errors[] = "La decisión {$key} tiene más de una conexión para la rama {$branch}.";
                    }
                }
                foreach ($connections as $connection) {
                    $handle = $connection['source_handle'] ?? null;
                    if (! is_string($handle) || ! in_array($handle, $branches, true)) {
                        $errors[] = "La decisión {$key} tiene una conexión con una rama no configurada.";
                    }
                }
            }

            $isTerminalInstruction = $type === BusinessFlowNodeType::Instruction->value
                && ($config['finish_chat'] ?? false) === true;
            $isFinalAction = $type === BusinessFlowNodeType::Action->value
                && ($config['action'] ?? null) === 'finalize_lead';

            if ($isTerminalInstruction && $connections !== []) {
                $errors[] = "La indicación final {$key} no debe tener conexiones de salida.";
            }
            if ($connections === [] && ! $isTerminalInstruction && ! $isFinalAction) {
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
