<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\Business\BusinessConversationSession;
use App\Enums\BusinessConversationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\BusinessMessageRequest;
use App\Models\Business;
use App\Models\BusinessApiClient;
use App\Models\BusinessConversation;
use App\Models\BusinessMessage;
use App\Services\Business\BusinessFlowRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicBusinessChatController extends Controller
{
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
     *   @OA\RequestBody(@OA\JsonContent(@OA\Property(property="visitor_id", type="string"))),
     *
     *   @OA\Response(response=201, description="Conversation and encrypted session created"), @OA\Response(response=403, description="Origin not allowed"))
     */
    public function start(Request $request, BusinessFlowRunner $runner, BusinessConversationSession $sessions): JsonResponse
    {
        $validated = $request->validate(['visitor_id' => ['nullable', 'string', 'max:255']]);
        $client = $this->client($request);
        $origin = (string) $request->attributes->get('business_origin');
        $result = $runner->start($this->business($request), $client, $origin, $validated['visitor_id'] ?? null);

        return response()->json(['message' => 'Business conversation started successfully.', 'data' => [
            'conversation_id' => $result['conversation']->uuid,
            'status' => $result['conversation']->status->value,
            'session' => $sessions->issue($result['conversation'], $client, $origin),
            'messages' => $this->messages($result['messages']),
        ]], 201);
    }

    /**
     * @OA\Post(path="/api/business/conversations/{conversation}/messages", tags={"Business Runtime"}, summary="Advance the published flow", security={{"businessKey":{}}},
     *
     *   @OA\Parameter(name="conversation", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *   @OA\Parameter(name="Origin", in="header", required=true, @OA\Schema(type="string")),
     *   @OA\Parameter(name="X-Bigmelo-Business-Session", in="header", required=true, @OA\Schema(type="string")),
     *
     *   @OA\RequestBody(required=true, @OA\JsonContent(required={"message"}, @OA\Property(property="message", type="string"))),
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
        $result = $runner->receive($model, $request->validated('message'), $request->header('Idempotency-Key'));

        return response()->json(['message' => 'Business message processed successfully.', 'data' => [
            'conversation_id' => $result['conversation']->uuid,
            'status' => $result['conversation']->status->value,
            'finished' => $result['conversation']->status !== BusinessConversationStatus::InProgress,
            'messages' => $this->messages($result['messages']),
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
    private function messages(iterable $messages): array
    {
        return collect($messages)->map(fn ($message): array => [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'created_at' => $message->created_at?->toISOString(),
        ])->values()->all();
    }
}
