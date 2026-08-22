<?php

namespace App\Services\Business;

use App\Classes\BusinessDecisionAI\BusinessDecisionAI;
use App\Classes\BusinessDecisionAI\BusinessDecisionResult;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BusinessFlowRunner
{
    public function __construct(
        private readonly BusinessFlowAI $ai,
        private readonly BusinessDecisionAI $decisionAI,
        private readonly BusinessLeadService $leads,
        private readonly BusinessUsageRecorder $usage,
        private readonly BusinessKnowledgeRetriever $knowledge,
        private readonly BusinessLocalization $localization,
    ) {}

    /** @return array{conversation: BusinessConversation, messages: array<int, BusinessMessage>} */
    public function start(Business $business, BusinessApiClient $client, string $origin, ?string $visitorId = null, string $locale = 'es'): array
    {
        $version = $business->flow?->publishedVersion?->load(['nodes', 'edges']);
        if (! $version) {
            throw ValidationException::withMessages(['flow' => 'El negocio no tiene un flow publicado.']);
        }
        $start = $version->nodes->first(fn (BusinessFlowNode $node): bool => ($node->config['start'] ?? false) === true);
        if (! $start) {
            throw ValidationException::withMessages(['flow' => 'El flow publicado no tiene nodo inicial.']);
        }

        return DB::transaction(function () use ($business, $client, $origin, $visitorId, $locale, $version, $start): array {
            $conversation = $business->conversations()->create([
                'uuid' => (string) Str::uuid(),
                'business_flow_version_id' => $version->id,
                'business_api_client_id' => $client->id,
                'status' => BusinessConversationStatus::InProgress,
                'locale' => $this->localization->normalize($locale),
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
    public function receive(
        BusinessConversation $conversation,
        string $content,
        ?string $idempotencyKey = null,
        array $fields = [],
        ?string $locale = null,
    ): array {
        return DB::transaction(function () use ($conversation, $content, $idempotencyKey, $fields, $locale): array {
            $conversation = BusinessConversation::query()->lockForUpdate()->findOrFail($conversation->id);
            if ($conversation->status !== BusinessConversationStatus::InProgress) {
                throw ValidationException::withMessages(['conversation' => 'La conversación ya finalizó.']);
            }

            if ($locale !== null) {
                $conversation->update(['locale' => $this->localization->normalize($locale, $conversation->locale ?: 'es')]);
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

            $structuredFields = $this->normalizeStructuredFields($fields);
            $tokens = $this->usage->estimateTokens($content);
            $messageData = ['locale' => $conversation->locale];
            if ($idempotencyKey) {
                $messageData['idempotency_key'] = $idempotencyKey;
            }
            if ($structuredFields !== []) {
                $messageData['fields'] = $structuredFields;
            }
            $visitorMessage = $conversation->messages()->create([
                'node_key' => $conversation->current_node_key,
                'role' => 'visitor',
                'content' => $content,
                'data' => $messageData,
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
        $requiredFields = $this->instructionRequiredFields($conversation, $node);
        $optionalFields = $this->instructionOptionalFields($conversation, $node, $requiredFields);
        $locale = $this->localization->normalize($conversation->locale);
        $messageText = $this->interpolate($this->localization->nodeMessage($node->config ?? [], $locale), $conversation->context ?? [], $locale);
        $messageText = str_replace('{{contact_request}}', $this->localization->contactRequest($requiredFields, $optionalFields, $locale), $messageText);
        $tokens = $this->usage->estimateTokens($messageText);
        $messageData = ['locale' => $locale];
        if ($requiredFields !== []) {
            $messageData['required_fields'] = $requiredFields;
        }
        if ($optionalFields !== []) {
            $messageData['optional_fields'] = $optionalFields;
        }
        $message = $conversation->messages()->create([
            'node_key' => $node->node_key,
            'role' => 'assistant',
            'content' => $messageText,
            'data' => $messageData,
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

        if (($node->config['finish_chat'] ?? false) === true) {
            $conversation->update([
                'status' => BusinessConversationStatus::Completed,
                'completed_at' => now(),
                'last_activity_at' => now(),
                'end_reason' => 'terminal_instruction',
            ]);
            Log::info('Business conversation completed by terminal instruction.', [
                'business_id' => $conversation->business_id,
                'conversation_id' => $conversation->id,
                'node_key' => $node->node_key,
            ]);

            return ['stop' => true, 'finish_chat' => true, 'conversation_status' => 'completed'];
        }

        if (($node->config['wait_for_input'] ?? true) === true) {
            return ['stop' => true, 'wait_for_input' => true];
        }

        return ['next' => $this->nextNodeKey($conversation, $node->node_key, null)];
    }

    /** @return array<int, string> */
    private function instructionRequiredFields(BusinessConversation $conversation, BusinessFlowNode $node): array
    {
        $context = is_array($conversation->context) ? $conversation->context : [];
        if (($node->config['dynamic'] ?? null) === 'missing_fields') {
            return $this->normalizeFieldIdentifiers($context['missing_fields'] ?? []);
        }

        $required = $this->normalizeFieldIdentifiers($node->config['required_fields'] ?? []);
        if ($required === []) {
            $nextNodeKey = $this->nextNodeKey($conversation, $node->node_key, null);
            $nextNode = $nextNodeKey
                ? $conversation->version->nodes()->where('node_key', $nextNodeKey)->first()
                : null;

            if ($nextNode?->type === BusinessFlowNodeType::Action
                && ($nextNode->config['action'] ?? null) === 'extract_fields') {
                $required = $this->normalizeFieldIdentifiers($nextNode->config['required_fields'] ?? []);
            }
        }

        $leadData = is_array($context['lead_data'] ?? null) ? $context['lead_data'] : [];

        return $this->missingRequiredFields($required, $leadData);
    }

    /** @param array<int, string> $requiredFields @return array<int, string> */
    private function instructionOptionalFields(
        BusinessConversation $conversation,
        BusinessFlowNode $node,
        array $requiredFields,
    ): array {
        if (($node->config['dynamic'] ?? null) === 'missing_fields') {
            return [];
        }

        $optional = $this->normalizeFieldIdentifiers($node->config['optional_fields'] ?? []);
        if ($optional === []) {
            $nextNodeKey = $this->nextNodeKey($conversation, $node->node_key, null);
            $nextNode = $nextNodeKey
                ? $conversation->version->nodes()->where('node_key', $nextNodeKey)->first()
                : null;

            if ($nextNode?->type === BusinessFlowNodeType::Action
                && ($nextNode->config['action'] ?? null) === 'extract_fields') {
                $optional = $this->normalizeFieldIdentifiers($nextNode->config['optional_fields'] ?? []);
            }
        }

        $context = is_array($conversation->context) ? $conversation->context : [];
        $leadData = is_array($context['lead_data'] ?? null) ? $context['lead_data'] : [];

        return collect($optional)
            ->reject(fn (string $field): bool => in_array($field, $requiredFields, true))
            ->filter(fn (string $field): bool => ! $this->isCompleteField($field, $leadData[$field] ?? null))
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function normalizeFieldIdentifiers(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        return collect($fields)
            ->filter(fn (mixed $field): bool => is_string($field) && trim($field) !== '')
            ->map(fn (string $field): string => trim($field))
            ->unique()
            ->values()
            ->all();
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
        } elseif ($mode === 'knowledge_yes_no') {
            return $this->knowledgeDecision($conversation, $node);
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
    private function knowledgeDecision(BusinessConversation $conversation, BusinessFlowNode $node): array
    {
        $locale = $this->localization->normalize($conversation->locale);
        $question = $this->decisionQuestion($node, $locale);
        $visitorMessages = $conversation->messages()
            ->where('role', 'visitor')
            ->latest('id')
            ->limit(6)
            ->get(['content'])
            ->reverse()
            ->pluck('content')
            ->map(fn (string $content): string => trim($content))
            ->filter()
            ->implode("\n");
        $problem = $this->decisionProblem($conversation);
        $searchQuery = trim(implode("\n", array_filter([$problem, $visitorMessages])));
        $useSources = ($node->config['use_sources'] ?? true) === true;
        $retrieved = $useSources
            ? $this->knowledge->retrieve($conversation->business, $searchQuery)
            : $this->emptyKnowledgeResult();

        if ($retrieved['query_tokens'] > 0 || $retrieved['items'] !== []) {
            $this->usage->record([
                'business_id' => $conversation->business_id,
                'business_conversation_id' => $conversation->id,
                'event_type' => 'source_retrieval',
                'provider' => $retrieved['provider'],
                'model' => $retrieved['model'],
                'input_tokens' => $retrieved['query_tokens'],
                'metadata' => [
                    'chunk_ids' => $retrieved['chunk_ids'],
                    'context_tokens' => $retrieved['context_tokens'],
                    'latency_ms' => $retrieved['latency_ms'],
                ],
            ]);
        }

        $result = $this->decisionAI->evaluate(
            business: $conversation->business,
            question: $question,
            visitorContext: $visitorMessages,
            problem: $problem,
            businessDescription: ($node->config['use_business_description'] ?? true) === true
                ? $conversation->business->description
                : null,
            knowledge: $retrieved['items'],
            locale: $locale,
        );
        $minimumConfidence = (float) ($node->config['minimum_confidence'] ?? config('business-ai.decision.minimum_confidence', 0.55));
        $answer = $result->answer && $result->confidence >= $minimumConfidence;
        $branch = $answer ? 'yes' : 'no';
        $this->recordDecision($conversation, $node, $result, $retrieved, $branch, $minimumConfidence);

        $context = is_array($conversation->context) ? $conversation->context : [];
        $context['knowledge'] = [
            'chunk_ids' => $retrieved['chunk_ids'],
            'context_tokens' => $retrieved['context_tokens'],
            'query_tokens' => $retrieved['query_tokens'],
        ];
        $context['last_decision'] = [
            'node_key' => $node->node_key,
            'answer' => $branch,
            'confidence' => $result->confidence,
            'source_chunk_ids' => $result->sourceChunkIds,
        ];
        $conversation->update(['context' => $context, 'current_node_key' => $node->node_key]);

        return [
            'branch' => $branch,
            'next' => $this->nextNodeKey($conversation, $node->node_key, $branch),
            'answer' => $branch,
            'confidence' => $result->confidence,
            'minimum_confidence' => $minimumConfidence,
            'reason' => $result->reason,
            'source_chunk_ids' => $result->sourceChunkIds,
            'retrieved_chunk_ids' => $retrieved['chunk_ids'],
        ];
    }

    /** @return array<string, mixed> */
    private function action(BusinessConversation $conversation, BusinessFlowNode $node): array
    {
        $action = (string) ($node->config['action'] ?? '');
        if ($action === 'capture_problem' || $action === 'extract_fields') {
            $latestMessage = $conversation->messages()->where('role', 'visitor')->latest('id')->first();
            $latest = (string) $latestMessage?->content;
            $known = $conversation->context['lead_data'] ?? [];
            $structuredFields = $this->normalizeStructuredFields($latestMessage?->data['fields'] ?? []);
            $known = array_merge($known, $structuredFields);
            $result = $this->ai->extractLeadData($latest, $known, $action === 'capture_problem');
            $context = $conversation->context ?? [];
            $context['lead_data'] = array_merge($result->data['lead_data'] ?? $known, $structuredFields);
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

    /** @param array<string, mixed> $retrieved */
    private function recordDecision(
        BusinessConversation $conversation,
        BusinessFlowNode $node,
        BusinessDecisionResult $result,
        array $retrieved,
        string $branch,
        float $minimumConfidence,
    ): void {
        $this->usage->record([
            'business_id' => $conversation->business_id,
            'business_conversation_id' => $conversation->id,
            'event_type' => 'flow_decision',
            'provider' => $result->provider,
            'model' => $result->model,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
            'metadata' => [
                'node_key' => $node->node_key,
                'branch' => $branch,
                'confidence' => $result->confidence,
                'minimum_confidence' => $minimumConfidence,
                'source_chunk_ids' => $result->sourceChunkIds,
                'retrieved_chunk_ids' => $retrieved['chunk_ids'],
            ],
        ]);

        Log::info('Business knowledge decision evaluated.', [
            'business_id' => $conversation->business_id,
            'conversation_id' => $conversation->id,
            'node_key' => $node->node_key,
            'branch' => $branch,
            'confidence' => $result->confidence,
            'minimum_confidence' => $minimumConfidence,
            'source_chunk_ids' => $result->sourceChunkIds,
            'retrieved_chunk_ids' => $retrieved['chunk_ids'],
            'provider' => $result->provider,
            'model' => $result->model,
        ]);
    }

    private function decisionQuestion(BusinessFlowNode $node, string $locale): string
    {
        $questions = is_array($node->config['questions'] ?? null) ? $node->config['questions'] : [];

        return trim((string) ($questions[$locale] ?? $node->config['question'] ?? $questions['es'] ?? $questions['en'] ?? $node->title));
    }

    private function decisionProblem(BusinessConversation $conversation): ?string
    {
        $context = is_array($conversation->context) ? $conversation->context : [];
        $problem = trim((string) data_get($context, 'lead_data.project_summary', ''));
        if ($problem !== '') {
            return $problem;
        }

        $messages = $conversation->messages()->where('role', 'visitor')->latest('id')->limit(20)->get(['data']);
        foreach ($messages as $message) {
            $candidate = trim((string) data_get($message->data, 'fields.project_summary', ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array{content:string,items:array,chunk_ids:array,query_tokens:int,context_tokens:int,latency_ms:int,provider:null,model:null} */
    private function emptyKnowledgeResult(): array
    {
        return [
            'content' => '', 'items' => [], 'chunk_ids' => [], 'query_tokens' => 0,
            'context_tokens' => 0, 'latency_ms' => 0, 'provider' => null, 'model' => null,
        ];
    }

    /** @param array<string, mixed> $context */
    private function interpolate(string $message, array $context, string $locale): string
    {
        $missingFields = is_array($context['missing_fields'] ?? null) ? $context['missing_fields'] : [];
        $missing = $this->localization->fieldPhrases($missingFields, $locale);
        $missing = $this->localizedHumanList($missing, $locale);
        $phoneHint = $this->localization->phoneHint($missingFields, $locale);

        return str_replace(['{{missing_fields}}', '{{phone_hint}}'], [$missing, $phoneHint], $message);
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

    /** @param array<int, string> $values */
    private function localizedHumanList(array $values, string $locale): string
    {
        if (count($values) < 2) {
            return $values[0] ?? '';
        }

        $last = array_pop($values);

        return implode(', ', $values).($locale === 'en' ? ' and ' : ' y ').$last;
    }

    /** @return array<string, string> */
    private function normalizeStructuredFields(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        $allowed = ['full_name', 'email', 'phone', 'whatsapp', 'company', 'website', 'project_summary'];

        return collect($fields)
            ->filter(fn (mixed $value, mixed $field): bool => is_string($field) && in_array($field, $allowed, true) && filled($value))
            ->map(function (mixed $value, string $field): string {
                $value = trim((string) $value);

                return match ($field) {
                    'email' => mb_strtolower($value),
                    'phone', 'whatsapp' => '+'.preg_replace('/\D+/', '', $value),
                    default => $value,
                };
            })
            ->all();
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
