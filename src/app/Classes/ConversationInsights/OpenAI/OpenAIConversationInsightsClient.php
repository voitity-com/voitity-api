<?php

namespace App\Classes\ConversationInsights\OpenAI;

use App\Classes\ConversationInsights\ConversationClassification;
use App\Classes\ConversationInsights\ConversationInsightsClient;
use App\Enums\ConversationCategory;
use App\Models\Chat;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIConversationInsightsClient implements ConversationInsightsClient
{
    public function classify(Chat $chat): ConversationClassification
    {
        $model = (string) config('insights.classification.model', 'gpt-4o-mini');
        $messages = $chat->messages()
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit((int) config('insights.classification.max_messages', 200))
            ->get(['id', 'type', 'text'])
            ->map(fn ($message): array => [
                'id' => $message->id,
                'role' => $message->type === 'question' ? 'visitor' : 'profile',
                'text' => $message->text,
            ])->all();

        $categories = array_map(fn (ConversationCategory $category): string => $category->value, ConversationCategory::cases());
        $response = Http::withToken((string) config('services.openai.api_key'))
            ->timeout(45)
            ->retry(2, 250)
            ->post(rtrim((string) config('chatai.drivers.openai.base_url'), '/').'/chat/completions', [
                'model' => $model,
                'temperature' => 0,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Classify the primary business goal of this Bigmelo profile conversation. Purchase intent is not a completed purchase. Return evidence IDs only from the transcript.',
                    ],
                    ['role' => 'user', 'content' => json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'conversation_classification',
                        'strict' => true,
                        'schema' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'primary_category' => ['type' => 'string', 'enum' => $categories],
                                'secondary_categories' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => $categories]],
                                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                                'summary' => ['type' => 'string'],
                                'evidence_message_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            ],
                            'required' => ['primary_category', 'secondary_categories', 'confidence', 'summary', 'evidence_message_ids'],
                        ],
                    ],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException("Conversation classification failed with HTTP {$response->status()}.");
        }

        $content = $response->json('choices.0.message.content');
        $result = is_string($content) ? json_decode($content, true) : null;
        $primary = ConversationCategory::tryFrom((string) ($result['primary_category'] ?? ''));

        if (! is_array($result) || ! $primary) {
            throw new RuntimeException('Conversation classification returned an invalid structured result.');
        }

        return new ConversationClassification(
            $primary,
            array_values(array_filter((array) ($result['secondary_categories'] ?? []), fn ($value): bool => ConversationCategory::tryFrom((string) $value) !== null)),
            min(1, max(0, (float) ($result['confidence'] ?? 0))),
            mb_substr(trim((string) ($result['summary'] ?? '')), 0, 1000),
            array_values(array_map('intval', (array) ($result['evidence_message_ids'] ?? []))),
            $model,
        );
    }
}
