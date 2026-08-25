<?php

namespace App\Listeners;

use App\Classes\ChatAIService\AnswerBuilder;
use App\Events\MessageStored;
use App\Exceptions\ChatAIService\ChatAIAnswerGenerationFailed;
use App\Jobs\ProcessStoredMessageJob;
use App\Models\Message;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Support\Facades\Log;

class ProcessStoredMessage
{
    public function __construct(private readonly AnswerBuilder $answerBuilder) {}

    public function handle(MessageStored $event): void
    {
        if (config('queue.default') !== 'sync') {
            ProcessStoredMessageJob::dispatch($event->message->id);

            return;
        }

        $this->process($event);
    }

    public function process(MessageStored $event): void
    {
        $message = $event->message->loadMissing(['profile', 'chat']);

        if ($message->type !== 'question') {
            return;
        }

        if (! $message->profile) {
            Log::warning('Message profile relation missing, skipping answer build.', [
                'message_id' => $message->id,
            ]);

            return;
        }

        $data = $message->data ?? [];

        if (($data['processing'] ?? false) === true || isset($data['processed_at'])) {
            return;
        }

        $data['processing'] = true;
        $message->data = $data;
        $message->save();

        try {
            $answer = $this->answerBuilder->getAnswer($message->profile, $message);
            $event->answer = $answer;

            $data = $message->fresh()->data ?? [];
            $data['processing'] = false;
            $data['processed_at'] = now()->toIso8601String();
            $data['answer_message_id'] = $answer->toArray()['message_id'] ?? null;

            $message->data = $data;
            $message->save();
        } catch (ChatAIAnswerGenerationFailed $e) {
            $chatAIError = $e->context();
            $data = $message->fresh()->data ?? [];
            $data['processing'] = false;
            $data['processing_error'] = $e->getMessage();
            $data['chat_ai_error'] = $chatAIError;

            $message->data = $data;
            $message->save();

            $this->notifyAiResponseFailed($message, $e->getMessage());

            Log::warning('Failed to build message answer from chat AI provider.', [
                'message_id' => $message->id,
                'source' => $chatAIError['source'] ?? null,
                'status' => $chatAIError['status'] ?? null,
                'request_url' => $chatAIError['request_url'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $data = $message->fresh()->data ?? [];
            $data['processing'] = false;
            $data['processing_error'] = $e->getMessage();

            $message->data = $data;
            $message->save();

            $this->notifyAiResponseFailed($message, $e->getMessage());

            Log::error('Failed to build message answer.', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function notifyAiResponseFailed(Message $message, string $reason): void
    {
        $message->loadMissing('profile.user');
        $profile = $message->profile;

        if (! $profile || ! $profile->user instanceof User) {
            return;
        }

        app(NotificationDispatcher::class)->send($profile->user, 'ai_response_failed', [
            'profile' => $profile->name ?: "Profile {$profile->id}",
            'profile_id' => $profile->id,
            'chat_id' => $message->chat_id,
            'message_id' => $message->id,
            'reason' => $reason,
            'action_url' => "/dashboard/profiles/{$profile->id}/chats/{$message->chat_id}",
        ]);
        app(NotificationDispatcher::class)->sendToAdmins('external_integration_error', [
            'service' => 'Chat AI provider',
            'message' => $reason,
        ]);
    }
}
