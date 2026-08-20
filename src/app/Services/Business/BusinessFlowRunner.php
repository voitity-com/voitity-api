<?php

namespace App\Services\Business;

use App\Classes\BusinessFlowAI\BusinessFlowAI;
use App\Classes\BusinessFlowAI\BusinessFlowAIResult;
use App\Enums\BusinessConversationStatus;
use App\Enums\BusinessFlowNodeType;
use App\Models\Business;
use App\Models\BusinessApiClient;
use App\Models\BusinessConversation;
use App\Models\BusinessFlowEdge;
use App\Models\BusinessFlowNode;
use App\Models\BusinessMessage;
use App\Models\BusinessNodeExecution;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BusinessFlowRunner
{
    public function __construct(
        private readonly BusinessFlowAI $ai,
        private readonly BusinessLeadService $leads,
        private readonly BusinessUsageRecorder $usage,
        private readonly BusinessKnowledgeRetriever $knowledge,
    ) {}

    /** @return array{conversation: BusinessConversation, messages: array<int, BusinessMessage>} */
    public function start(Business $business, BusinessApiClient $client, string $origin, ?string $visitorId = null): array
    {
        $version = $business->flow?->publishedVersion?->load(['nodes', 'edges']);
        if (! $version) {
            throw ValidationException::withMessages(['flow' => 'El negocio no tiene un flow publicado.']);
        }
        $start = $version->nodes->first(fn (BusinessFlowNode $node): bool => ($node->config['start'] ?? false) === true);
        if (! $start) {
            throw ValidationException::withMessages(['flow' => 'El flow publicado no tiene nodo inicial.']);
        }

        return DB::transaction(function () use ($business, $client, $origin, $visitorId, $version, $start): array {
            $conversation = $business->conversations()->create([
                'uuid' => (string) Str::uuid(),
                'business_flow_version_id' => $version->id,
                'business_api_client_id' => $client->id,
                'status' => BusinessConversationStatus::InProgress,
                'current_node_key' => $start->node_key,
                'context' => ['lead_data' => []],
                'origin' => $origin,
                'visitor_id_hash' => filled($visitorId) ? hash('sha256', (string) $visitorId) : null,
                'started_at' => now(),
                'last_activity_at' => now(),
            ]);
            $messages = $this->advance($conversation, $start->node_key);

            return ['conversation' => $conversation->fresh(), 'messages' => $messages];
        });
    }

    /** @return array{conversation: BusinessConversation, messages: array<int, BusinessMessage>} */
    public function receive(BusinessConversation $conversation, string $content, ?string $idempotencyKey = null): array
    {
        return DB::transaction(function () use ($conversation, $content, $idempotencyKey): array {
            $conversation = BusinessConversation::query()->lockForUpdate()->findOrFail($conversation->id);
            if ($conversation->status !== BusinessConversationStatus::InProgress) {
                throw ValidationException::withMessages(['conversation' => 'La conversación ya finalizó.']);
            }

            if ($idempotencyKey) {
                $existing = $conversation->messages()
                    ->where('role', 'visitor')
                    ->where('data->idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    return ['conversation' => $conversation, 'messages' => []];
                }
            }

            $tokens = $this->usage->estimateTokens($content);
            $visitorMessage = $conversation->messages()->create([
                'node_key' => $conversation->current_node_key,
                'role' => 'visitor',
                'content' => $content,
                'data' => $idempotencyKey ? ['idempotency_key' => $idempotencyKey] : [],
                'input_tokens' => $tokens,
                'total_tokens' => $tokens,
            ]);
            $this->usage->record([
                'business_id' => $conversation->business_id,
                'business_conversation_id' => $conversation->id,
                'business_message_id' => $visitorMessage->id,
                'event_type' => 'message_received',
                'input_tokens' => $tokens,
            ]);

            $retrieved = $this->knowledge->retrieve($conversation->business, $content);
            if ($retrieved['content'] !== '') {
                $context = $conversation->context ?? [];
                $context['knowledge'] = $retrieved;
                $conversation->context = $context;
                $conversation->save();
                $this->usage->record([
                    'business_id' => $conversation->business_id,
                    'business_conversation_id' => $conversation->id,
                    'event_type' => 'source_retrieval',
                    'input_tokens' => $retrieved['tokens'],
                    'metadata' => ['chunk_ids' => $retrieved['chunk_ids']],
                ]);
            }

            $next = $this->nextNodeKey($conversation, $conversation->current_node_key, null);
            if (! $next) {
                $this->fail($conversation, 'missing_connection');

                return ['conversation' => $conversation->fresh(), 'messages' => []];
            }
            $messages = $this->advance($conversation, $next);
            $conversation->update(['last_activity_at' => now()]);

            return ['conversation' => $conversation->fresh(), 'messages' => $messages];
        });
    }

    /** @return array<int, BusinessMessage> */
    private function advance(BusinessConversation $conversation, string $nodeKey): array
    {
        $messages = [];
        $stopped = false;
        for ($step = 0; $step < 50; $step++) {
            $node = $conversation->version->nodes()->where('node_key', $nodeKey)->first();
            if (! $node) {
                $this->fail($conversation, 'missing_node');
                break;
            }
            $execution = BusinessNodeExecution::query()->create([
                'business_conversation_id' => $conversation->id,
                'business_flow_version_id' => $conversation->business_flow_version_id,
                'node_key' => $node->node_key,
                'status' => 'running',
                'input' => ['context' => $conversation->context],
                'started_at' => now(),
            ]);

            try {
                $outcome = match ($node->type) {
                    BusinessFlowNodeType::Instruction => $this->instruction($conversation, $node, $messages),
                    BusinessFlowNodeType::Decision => $this->decision($conversation, $node),
                    BusinessFlowNodeType::Action => $this->action($conversation, $node),
                };
                $execution->update(['status' => 'completed', 'output' => $outcome, 'completed_at' => now()]);
            } catch (\Throwable $e) {
                $execution->update(['status' => 'failed', 'last_error' => $e->getMessage(), 'completed_at' => now()]);
                $this->fail($conversation, 'node_failed');
                throw $e;
            }

            if (($outcome['stop'] ?? false) === true) {
                $stopped = true;
                break;
            }
            $nodeKey = (string) ($outcome['next'] ?? '');
            if ($nodeKey === '') {
                $this->fail($conversation, 'missing_connection');
                break;
            }
        }

        if (! $stopped && $conversation->status === BusinessConversationStatus::InProgress) {
            $this->fail($conversation, 'step_limit');
        }

        return $messages;
    }

    /** @param array<int, BusinessMessage> $messages @return array<string, mixed> */
    private function instruction(BusinessConversation $conversation, BusinessFlowNode $node, array &$messages): array
    {
        $messageText = $this->interpolate((string) ($node->config['message'] ?? ''), $conversation->context ?? []);
        $tokens = $this->usage->estimateTokens($messageText);
        $message = $conversation->messages()->create([
            'node_key' => $node->node_key,
            'role' => 'assistant',
            'content' => $messageText,
            'output_tokens' => $tokens,
            'total_tokens' => $tokens,
        ]);
        $messages[] = $message;
        $this->usage->record([
            'business_id' => $conversation->business_id,
            'business_conversation_id' => $conversation->id,
            'business_message_id' => $message->id,
            'event_type' => 'message_sent',
            'output_tokens' => $tokens,
        ]);
        $conversation->update(['current_node_key' => $node->node_key, 'last_activity_at' => now()]);

        if (($node->config['wait_for_input'] ?? true) === true) {
            return ['stop' => true, 'wait_for_input' => true];
        }

        return ['next' => $this->nextNodeKey($conversation, $node->node_key, null)];
    }

    /** @return array<string, mixed> */
    private function decision(BusinessConversation $conversation, BusinessFlowNode $node): array
    {
        $mode = (string) ($node->config['mode'] ?? '');
        $latest = (string) $conversation->messages()->where('role', 'visitor')->latest('id')->value('content');
        if ($mode === 'technology_interest') {
            $result = $this->ai->classifyTechnology($latest);
            $branch = (string) ($result->data['branch'] ?? 'other');
            $this->recordAI($conversation, 'flow_decision', $result);
        } elseif ($mode === 'required_fields_complete') {
            $required = $node->config['required_fields'] ?? [];
            $data = $conversation->context['lead_data'] ?? [];
            $missing = $this->missingRequiredFields($required, $data);
            $context = $conversation->context ?? [];
            $context['missing_fields'] = $missing;
            $conversation->update(['context' => $context]);
            $branch = $missing === [] ? 'complete' : 'incomplete';
        } else {
            throw ValidationException::withMessages(['flow' => "Modo de decisión no soportado: {$mode}."]);
        }
        $conversation->update(['current_node_key' => $node->node_key]);

        return ['branch' => $branch, 'next' => $this->nextNodeKey($conversation, $node->node_key, $branch)];
    }

    /** @return array<string, mixed> */
    private function action(BusinessConversation $conversation, BusinessFlowNode $node): array
    {
        $action = (string) ($node->config['action'] ?? '');
        if ($action === 'capture_problem' || $action === 'extract_fields') {
            $latest = (string) $conversation->messages()->where('role', 'visitor')->latest('id')->value('content');
            $known = $conversation->context['lead_data'] ?? [];
            $result = $this->ai->extractLeadData($latest, $known, $action === 'capture_problem');
            $context = $conversation->context ?? [];
            $context['lead_data'] = $result->data['lead_data'] ?? $known;
            $conversation->update(['context' => $context, 'current_node_key' => $node->node_key]);
            $this->recordAI($conversation, $action === 'capture_problem' ? 'problem_extraction' : 'field_extraction', $result);

            return ['next' => $this->nextNodeKey($conversation, $node->node_key, null), 'fields' => array_keys($context['lead_data'])];
        }
        if ($action === 'analyze_solution') {
            $context = $conversation->context ?? [];
            $leadData = is_array($context['lead_data'] ?? null) ? $context['lead_data'] : [];
            $problem = (string) ($leadData['project_summary'] ?? '');
            throw_if(blank($problem), ValidationException::withMessages(['flow' => 'No se puede analizar una solución sin el problema del cliente.']));
            $result = $this->ai->summarizeSolution($problem, $leadData);
            $summary = trim((string) ($result->data['summary'] ?? ''));
            throw_if($summary === '', ValidationException::withMessages(['flow' => 'La IA no generó una posible solución.']));
            $context['ai_solution_summary'] = $summary;
            $conversation->update(['context' => $context, 'current_node_key' => $node->node_key]);
            $this->recordAI($conversation, 'lead_analysis', $result);

            return ['next' => $this->nextNodeKey($conversation, $node->node_key, null), 'analysis_ready' => true];
        }
        if ($action === 'finalize_lead') {
            $lead = $this->leads->finalize($conversation, $node->node_key);
            $conversation->update([
                'status' => BusinessConversationStatus::Completed,
                'current_node_key' => $node->node_key,
                'completed_at' => now(),
                'last_activity_at' => now(),
                'end_reason' => 'flow_completed',
            ]);

            return ['stop' => true, 'lead_id' => $lead->id, 'conversation_status' => 'completed'];
        }

        throw ValidationException::withMessages(['flow' => "Acción no soportada: {$action}."]);
    }

    private function nextNodeKey(BusinessConversation $conversation, string $source, ?string $handle): ?string
    {
        $query = BusinessFlowEdge::query()
            ->where('business_flow_version_id', $conversation->business_flow_version_id)
            ->where('source_node_key', $source);
        if ($handle !== null) {
            $query->where('source_handle', $handle);
        } else {
            $query->whereNull('source_handle');
        }

        return $query->value('target_node_key');
    }

    private function recordAI(BusinessConversation $conversation, string $eventType, BusinessFlowAIResult $result): void
    {
        $this->usage->record([
            'business_id' => $conversation->business_id,
            'business_conversation_id' => $conversation->id,
            'event_type' => $eventType,
            'provider' => $result->provider,
            'model' => $result->model,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
        ]);
    }

    /** @param array<string, mixed> $context */
    private function interpolate(string $message, array $context): string
    {
        $labels = [
            'full_name' => 'nombre y apellido', 'email' => 'email válido',
            'phone' => 'teléfono con indicativo de país', 'whatsapp' => 'WhatsApp con indicativo de país',
            'company' => 'empresa', 'website' => 'sitio web', 'project_summary' => 'descripción completa del problema',
        ];
        $missing = collect($context['missing_fields'] ?? [])->map(fn (string $field): string => $labels[$field] ?? $field)->implode(', ');

        return str_replace('{{missing_fields}}', $missing, $message);
    }

    /** @param array<int, string> $required @param array<string, mixed> $data @return array<int, string> */
    private function missingRequiredFields(array $required, array $data): array
    {
        return collect($required)
            ->filter(fn (string $field): bool => ! $this->isCompleteField($field, $data[$field] ?? null))
            ->values()
            ->all();
    }

    private function isCompleteField(string $field, mixed $value): bool
    {
        $value = trim((string) $value);
        if ($value === '') {
            return false;
        }

        return match ($field) {
            'full_name' => count(preg_split('/\s+/u', $value) ?: []) >= 2,
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'phone', 'whatsapp' => preg_match('/^\+[1-9]\d{7,14}$/', $value) === 1,
            'project_summary' => mb_strlen($value) >= 20,
            default => true,
        };
    }

    private function fail(BusinessConversation $conversation, string $reason): void
    {
        $conversation->update([
            'status' => BusinessConversationStatus::Failed,
            'completed_at' => now(),
            'last_activity_at' => now(),
            'end_reason' => $reason,
        ]);
    }
}
