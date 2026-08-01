<?php

namespace App\Jobs\Insights;

use App\Classes\ConversationInsights\ConversationInsightsClient;
use App\Enums\ChatAnalysisStatus;
use App\Models\Chat;
use App\Models\ChatAnalysis;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ClassifyConversation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $chatId) {}

    public function handle(ConversationInsightsClient $client): void
    {
        $chat = Chat::query()->with('messages')->findOrFail($this->chatId);
        $analysis = ChatAnalysis::query()->firstOrCreate(
            ['chat_id' => $chat->id],
            ['profile_id' => $chat->profile_id, 'status' => ChatAnalysisStatus::Pending],
        );
        $classification = $client->classify($chat);
        $threshold = (float) config('insights.classification.confidence_threshold', 0.65);

        $analysis->update([
            'status' => $classification->confidence < $threshold ? ChatAnalysisStatus::NeedsReview : ChatAnalysisStatus::Completed,
            'primary_category' => $classification->primaryCategory,
            'secondary_categories' => $classification->secondaryCategories,
            'confidence' => $classification->confidence,
            'summary' => $classification->summary,
            'evidence_message_ids' => $classification->evidenceMessageIds,
            'model' => $classification->model,
            'prompt_version' => config('insights.classification.prompt_version', 'v1'),
            'taxonomy_version' => config('insights.classification.taxonomy_version', 'v1'),
            'analyzed_at' => now(),
            'error' => null,
        ]);

        Log::info('Conversation classified for profile insights.', [
            'chat_id' => $chat->id,
            'profile_id' => $chat->profile_id,
            'category' => $classification->primaryCategory->value,
            'confidence' => $classification->confidence,
            'status' => $analysis->status->value,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $chat = Chat::query()->find($this->chatId);

        if ($chat instanceof Chat) {
            ChatAnalysis::query()->updateOrCreate(
                ['chat_id' => $chat->id],
                [
                    'profile_id' => $chat->profile_id,
                    'status' => ChatAnalysisStatus::Failed,
                    'error' => mb_substr($exception->getMessage(), 0, 2000),
                ],
            );
        }

        Log::error('Conversation classification failed.', [
            'chat_id' => $this->chatId,
            'error' => $exception->getMessage(),
        ]);
    }
}
