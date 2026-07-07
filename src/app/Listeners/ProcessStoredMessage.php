<?php

namespace App\Listeners;

use App\Classes\ChatAIService\AnswerBuilder;
use App\Events\MessageStored;
use App\Exceptions\ChatAIService\ChatAIAnswerGenerationFailed;
use Illuminate\Support\Facades\Log;

class ProcessStoredMessage
{
    public function __construct(private readonly AnswerBuilder $answerBuilder) {}

    public function handle(MessageStored $event): void
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

            Log::error('Failed to build message answer.', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
