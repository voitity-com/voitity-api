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
                    'messages' => [
                        'es' => '¡Hola! Cuéntanos qué problema quieres resolver o en qué crees que podemos ayudarte.',
                        'en' => 'Hello! Tell us what problem you want to solve or how you think we could help.',
                    ],
                    'wait_for_input' => true,
                    'required_fields' => ['project_summary'],
                    'start' => true,
                ]),
                $this->node('qualify', 'decision', '¿Es una necesidad tecnológica?', 760, 300, [
                    'mode' => 'technology_interest',
                    'branches' => ['technology', 'other'],
                ]),
                $this->node('redirect', 'instruction', 'Orientar al usuario', 1120, 90, [
                    'message' => 'Estamos para ayudarte con tecnología y automatización, como desarrollo de software, IA, datos e infraestructura. Cuéntanos si tienes una necesidad relacionada.',
                    'messages' => [
                        'es' => 'Estamos para ayudarte con tecnología y automatización, como desarrollo de software, IA, datos e infraestructura. Cuéntanos si tienes una necesidad relacionada.',
                        'en' => 'We can help with technology and automation, such as software development, AI, data, and infrastructure. Tell us if you have a related need.',
                    ],
                    'wait_for_input' => true,
                ]),
                $this->node('capture_problem', 'action', 'Guardar el problema descrito', 1120, 500, [
                    'action' => 'capture_problem',
                ]),
                $this->node('problem_complete', 'decision', '¿El problema está suficientemente descrito?', 1480, 500, [
                    'mode' => 'required_fields_complete',
                    'required_fields' => ['project_summary'],
                    'branches' => ['complete', 'incomplete'],
                ]),
                $this->node('request_problem_details', 'instruction', 'Profundizar en el problema', 1840, 260, [
                    'message' => 'Para poder ayudarte bien, cuéntanos un poco más: ¿qué situación o proceso quieres resolver, quién lo usa y qué resultado esperas obtener?',
                    'messages' => [
                        'es' => 'Para poder ayudarte bien, cuéntanos un poco más: ¿qué situación o proceso quieres resolver, quién lo usa y qué resultado esperas obtener?',
                        'en' => 'To help you well, tell us a little more: what situation or process do you want to improve, who uses it, and what result do you expect?',
                    ],
                    'wait_for_input' => true,
                    'required_fields' => ['project_summary'],
                ]),
                $this->node('request_details', 'instruction', 'Solicitar datos', 1840, 620, [
                    'message' => '{{contact_request}}',
                    'messages' => ['es' => '{{contact_request}}', 'en' => '{{contact_request}}'],
                    'wait_for_input' => true,
                    'required_fields' => ['full_name', 'email', 'phone', 'whatsapp'],
                    'optional_fields' => ['company', 'website'],
                ]),
                $this->node('extract_details', 'action', 'Obtener datos de la respuesta', 2200, 620, [
                    'action' => 'extract_fields',
                    'required_fields' => ['full_name', 'email', 'phone', 'whatsapp', 'project_summary'],
                    'optional_fields' => ['company', 'website'],
                ]),
                $this->node('details_complete', 'decision', '¿Los datos están completos?', 2560, 620, [
                    'mode' => 'required_fields_complete',
                    'required_fields' => ['full_name', 'email', 'phone', 'whatsapp', 'project_summary'],
                    'branches' => ['complete', 'incomplete'],
                ]),
                $this->node('missing_details', 'instruction', 'Solicitar datos faltantes', 2920, 260, [
                    'message' => 'Para continuar necesitamos: {{missing_fields}}.{{phone_hint}}',
                    'messages' => [
                        'es' => 'Para continuar necesitamos: {{missing_fields}}.{{phone_hint}}',
                        'en' => 'To continue, we need: {{missing_fields}}.{{phone_hint}}',
                    ],
                    'wait_for_input' => true,
                    'dynamic' => 'missing_fields',
                ]),
                $this->node('analyze_solution', 'action', 'Plantear solución interna con IA', 2920, 800, [
                    'action' => 'analyze_solution',
                    'visibility' => 'internal',
                ]),
                $this->node('closing', 'instruction', 'Confirmación final', 3280, 800, [
                    'message' => 'Muchas gracias. Analizaremos la información y te contactaremos. La idea es tener un prototipo rápido en máximo dos semanas y luego seguir mejorándolo y puliéndolo.',
                    'messages' => [
                        'es' => 'Muchas gracias. Analizaremos la información y te contactaremos. La idea es tener un prototipo rápido en máximo dos semanas y luego seguir mejorándolo y puliéndolo.',
                        'en' => 'Thank you. We will analyze the information and contact you. The goal is to have a rapid prototype in no more than two weeks, then continue improving and refining it.',
                    ],
                    'wait_for_input' => false,
                ]),
                $this->node('finalize', 'action', 'Crear lead y enviar correos', 3640, 800, [
                    'action' => 'finalize_lead',
                    'operations' => ['create_lead', 'notify_business', 'notify_visitor', 'complete_conversation'],
                ]),
            ],
            'edges' => [
                $this->edge('welcome-qualify', 'welcome', 'qualify'),
                $this->edge('qualify-redirect', 'qualify', 'redirect', 'other', 'No relacionado'),
                $this->edge('qualify-capture', 'qualify', 'capture_problem', 'technology', 'Tecnología'),
                $this->edge('redirect-qualify', 'redirect', 'qualify'),
                $this->edge('capture-problem-check', 'capture_problem', 'problem_complete'),
                $this->edge('problem-check-details', 'problem_complete', 'request_details', 'complete', 'Problema completo'),
                $this->edge('problem-check-clarify', 'problem_complete', 'request_problem_details', 'incomplete', 'Falta contexto'),
                $this->edge('problem-clarify-capture', 'request_problem_details', 'capture_problem'),
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
