<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\Business\BusinessConversationSession;
use App\Enums\BusinessConversationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessMessageRequest;
use App\Http\Requests\StartBusinessConversationRequest;
use App\Models\Business;
use App\Models\BusinessApiClient;
use App\Models\BusinessConversation;
use App\Models\BusinessMessage;
use App\Services\Business\BusinessFlowRunner;
use App\Services\Business\BusinessLocalization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicBusinessChatController extends Controller
{
    public function __construct(private readonly BusinessLocalization $localization) {}

    /**
     * @OA\Get(path="/api/business/widget", tags={"Business Runtime"}, summary="Get the active Business widget configuration", security={{"businessKey":{}}},
     *
     *   @OA\Parameter(name="Origin", in="header", required=true, @OA\Schema(type="string", example="http://localhost:3001")),
     *
     *   @OA\Response(response=200, description="Widget appearance and locale"), @OA\Response(response=404, description="Widget is disabled"))
     */
    public function widget(Request $request): JsonResponse
    {
        $business = $this->business($request);
        $settings = $business->settings;
        abort_unless($settings?->widget_enabled, 404, 'Widget is not enabled.');

        return response()->json(['message' => 'Business widget retrieved successfully.', 'data' => [
            'business_name' => $business->name,
            'locale' => $settings->locale,
            'title' => $settings->widget_title,
            'button_label' => $settings->widget_button_label,
            'welcome_message' => $settings->widget_welcome_message,
            'primary_color' => $settings->widget_primary_color,
            'position' => $settings->widget_position,
        ]]);
    }

    /**
     * @OA\Post(path="/api/business/conversations", tags={"Business Runtime"}, summary="Start a guided Business conversation", security={{"businessKey":{}}},
     *
     *   @OA\Parameter(name="Origin", in="header", required=true, @OA\Schema(type="string", example="http://localhost:3001")),
     *
     *   @OA\RequestBody(@OA\JsonContent(@OA\Property(property="visitor_id", type="string"), @OA\Property(property="locale", type="string", enum={"es","en"}))),
     *
     *   @OA\Response(response=201, description="Conversation and encrypted session created"), @OA\Response(response=403, description="Origin not allowed"))
     */
    public function start(StartBusinessConversationRequest $request, BusinessFlowRunner $runner, BusinessConversationSession $sessions): JsonResponse
    {
        $validated = $request->validated();
        $client = $this->client($request);
        $origin = (string) $request->attributes->get('business_origin');
        $business = $this->business($request);
        $locale = $this->localization->normalize($validated['locale'] ?? $business->settings?->locale ?? 'es');
        $result = $runner->start($business, $client, $origin, $validated['visitor_id'] ?? null, $locale);

        return response()->json(['message' => 'Business conversation started successfully.', 'data' => [
            'conversation_id' => $result['conversation']->uuid,
            'status' => $result['conversation']->status->value,
            'locale' => $result['conversation']->locale,
            'finished' => $result['conversation']->status !== BusinessConversationStatus::InProgress,
            'session' => $sessions->issue($result['conversation'], $client, $origin),
            'messages' => $this->messages($result['messages'], $result['conversation']->locale),
        ]], 201);
    }

    /**
     * @OA\Post(path="/api/business/conversations/{conversation}/messages", tags={"Business Runtime"}, summary="Advance the published flow", security={{"businessKey":{}}},
     *
     *   @OA\Parameter(name="conversation", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="Origin", in="header", required=true, @OA\Schema(type="string")),
     *   @OA\Parameter(name="X-Bigmelo-Business-Session", in="header", required=true, @OA\Schema(type="string")),
     *
     *   @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="message", type="string"), @OA\Property(property="locale", type="string", enum={"es","en"}), @OA\Property(property="fields", type="object"))),
     *
     *   @OA\Response(response=200, description="Flow advanced"), @OA\Response(response=401, description="Invalid session"))
     */
    public function message(
        BusinessMessageRequest $request,
        string $conversation,
        BusinessFlowRunner $runner,
        BusinessConversationSession $sessions,
    ): JsonResponse {
        $model = $this->conversation($request, $conversation, $sessions);
        $validated = $request->validated();
        $locale = $this->localization->normalize($validated['locale'] ?? $model->locale, $model->locale ?: 'es');
        $fields = is_array($validated['fields'] ?? null) ? $validated['fields'] : [];
        $content = trim((string) ($validated['message'] ?? ''));
        if ($content === '') {
            $content = $this->localization->fieldsAsMessage($fields, $locale);
        }
        $result = $runner->receive($model, $content, $request->header('Idempotency-Key'), $fields, $locale);

        return response()->json(['message' => 'Business message processed successfully.', 'data' => [
            'conversation_id' => $result['conversation']->uuid,
            'status' => $result['conversation']->status->value,
            'locale' => $result['conversation']->locale,
            'finished' => $result['conversation']->status !== BusinessConversationStatus::InProgress,
            'messages' => $this->messages($result['messages'], $result['conversation']->locale),
        ]]);
    }

    /**
     * @OA\Get(path="/api/business/conversations/{conversation}/status", tags={"Business Runtime"}, summary="Get conversation status", security={{"businessKey":{}}},
     *
     *   @OA\Parameter(name="conversation", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="Origin", in="header", required=true, @OA\Schema(type="string")),
     *   @OA\Parameter(name="X-Bigmelo-Business-Session", in="header", required=true, @OA\Schema(type="string")),
     *
     *   @OA\Response(response=200, description="Current status and finished flag"))
     */
    public function status(Request $request, string $conversation, BusinessConversationSession $sessions): JsonResponse
    {
        $model = $this->conversation($request, $conversation, $sessions);

        return response()->json(['message' => 'Business conversation status retrieved successfully.', 'data' => [
            'conversation_id' => $model->uuid,
            'status' => $model->status->value,
            'locale' => $model->locale,
            'finished' => $model->status !== BusinessConversationStatus::InProgress,
            'current_node' => $model->current_node_key,
            'started_at' => $model->started_at?->toISOString(),
            'completed_at' => $model->completed_at?->toISOString(),
        ]]);
    }

    private function conversation(Request $request, string $uuid, BusinessConversationSession $sessions): BusinessConversation
    {
        $client = $this->client($request);
        $business = $this->business($request);
        $origin = (string) $request->attributes->get('business_origin');
        $conversation = BusinessConversation::query()
            ->where('uuid', $uuid)
            ->where('business_id', $business->id)
            ->where('business_api_client_id', $client->id)
            ->firstOrFail();
        abort_unless($sessions->isValid($request->header('X-Bigmelo-Business-Session'), $conversation, $client, $origin), 401, 'Invalid conversation session.');

        return $conversation;
    }

    private function client(Request $request): BusinessApiClient
    {
        return $request->attributes->get('business_api_client');
    }

    private function business(Request $request): Business
    {
        return $request->attributes->get('business');
    }

    /** @param iterable<BusinessMessage> $messages @return array<int, array<string, mixed>> */
    private function messages(iterable $messages, string $fallbackLocale = 'es'): array
    {
        return collect($messages)->map(function ($message) use ($fallbackLocale): array {
            $payload = [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'locale' => $this->localization->normalize($message->data['locale'] ?? $fallbackLocale),
                'created_at' => $message->created_at?->toISOString(),
            ];
            $requiredFields = collect($message->data['required_fields'] ?? [])
                ->filter(fn (mixed $field): bool => is_string($field) && $field !== '')
                ->values()
                ->all();
            if ($requiredFields !== []) {
                $payload['required_fields'] = $requiredFields;
            }
            $optionalFields = collect($message->data['optional_fields'] ?? [])
                ->filter(fn (mixed $field): bool => is_string($field) && $field !== '')
                ->values()
                ->all();
            if ($optionalFields !== []) {
                $payload['optional_fields'] = $optionalFields;
            }
            if ($requiredFields !== [] || $optionalFields !== []) {
                $payload['fields'] = $this->localization->fieldDefinitions(
                    $requiredFields,
                    $optionalFields,
                    $payload['locale'],
                );
            }

            return $payload;
        })->values()->all();
    }
}
