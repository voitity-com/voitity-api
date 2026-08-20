<?php

namespace App\Services\Business;

class BusinessFlowTemplate
{
    /** @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>} */
    public function graph(): array
    {
        return [
            'nodes' => [
                $this->node('welcome', 'instruction', 'Bienvenida', 400, 300, [
                    'message' => '¡Hola! Cuéntanos qué problema quieres resolver o en qué crees que podemos ayudarte.',
                    'wait_for_input' => true,
                    'start' => true,
                ]),
                $this->node('qualify', 'decision', '¿Es una necesidad tecnológica?', 760, 300, [
                    'mode' => 'technology_interest',
                    'branches' => ['technology', 'other'],
                ]),
                $this->node('redirect', 'instruction', 'Orientar al usuario', 1120, 90, [
                    'message' => 'Estamos para ayudarte con tecnología y automatización, como desarrollo de software, IA, datos e infraestructura. Cuéntanos si tienes una necesidad relacionada.',
                    'wait_for_input' => true,
                ]),
                $this->node('capture_problem', 'action', 'Guardar el problema descrito', 1120, 500, [
                    'action' => 'capture_problem',
                ]),
                $this->node('request_details', 'instruction', 'Solicitar datos', 1480, 500, [
                    'message' => '¡Perfecto! Para continuar indícanos tu nombre y apellido, email, teléfono con indicativo de país y WhatsApp con indicativo de país. Empresa y sitio web son opcionales.',
                    'wait_for_input' => true,
                ]),
                $this->node('extract_details', 'action', 'Obtener datos de la respuesta', 1840, 500, [
                    'action' => 'extract_fields',
                    'required_fields' => ['full_name', 'email', 'phone', 'whatsapp', 'project_summary'],
                ]),
                $this->node('details_complete', 'decision', '¿Los datos están completos?', 2200, 500, [
                    'mode' => 'required_fields_complete',
                    'required_fields' => ['full_name', 'email', 'phone', 'whatsapp', 'project_summary'],
                    'branches' => ['complete', 'incomplete'],
                ]),
                $this->node('missing_details', 'instruction', 'Solicitar datos faltantes', 2560, 260, [
                    'message' => 'Para continuar necesitamos: {{missing_fields}}. Recuerda incluir el indicativo de país en teléfono y WhatsApp.',
                    'wait_for_input' => true,
                    'dynamic' => 'missing_fields',
                ]),
                $this->node('analyze_solution', 'action', 'Plantear solución interna con IA', 2560, 700, [
                    'action' => 'analyze_solution',
                    'visibility' => 'internal',
                ]),
                $this->node('closing', 'instruction', 'Confirmación final', 2920, 700, [
                    'message' => 'Muchas gracias. Analizaremos la información y te contactaremos. La idea es tener un prototipo rápido en máximo dos semanas y luego seguir mejorándolo y puliéndolo.',
                    'wait_for_input' => false,
                ]),
                $this->node('finalize', 'action', 'Crear lead y enviar correos', 3280, 700, [
                    'action' => 'finalize_lead',
                    'operations' => ['create_lead', 'notify_business', 'notify_visitor', 'complete_conversation'],
                ]),
            ],
            'edges' => [
                $this->edge('welcome-qualify', 'welcome', 'qualify'),
                $this->edge('qualify-redirect', 'qualify', 'redirect', 'other', 'No relacionado'),
                $this->edge('qualify-capture', 'qualify', 'capture_problem', 'technology', 'Tecnología'),
                $this->edge('redirect-qualify', 'redirect', 'qualify'),
                $this->edge('capture-details', 'capture_problem', 'request_details'),
                $this->edge('details-extract', 'request_details', 'extract_details'),
                $this->edge('extract-check', 'extract_details', 'details_complete'),
                $this->edge('check-missing', 'details_complete', 'missing_details', 'incomplete', 'Faltan datos'),
                $this->edge('missing-extract', 'missing_details', 'extract_details'),
                $this->edge('check-analysis', 'details_complete', 'analyze_solution', 'complete', 'Completo'),
                $this->edge('analysis-closing', 'analyze_solution', 'closing'),
                $this->edge('closing-finalize', 'closing', 'finalize'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function node(string $key, string $type, string $title, int $x, int $y, array $config): array
    {
        return compact('key', 'type', 'title', 'x', 'y', 'config');
    }

    /** @return array<string, mixed> */
    private function edge(
        string $key,
        string $source,
        string $target,
        ?string $sourceHandle = null,
        ?string $label = null,
    ): array {
        return [
            'key' => $key,
            'source' => $source,
            'target' => $target,
            'source_handle' => $sourceHandle,
            'label' => $label,
            'config' => [],
        ];
    }
}
