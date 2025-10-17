<?php

namespace App\Listeners;

use App\Classes\ChatAIService\AnswerBuilder;
use App\Events\MessageStored;
use Illuminate\Support\Facades\Log;

class ProcessStoredMessage
{
    public function __construct(private readonly AnswerBuilder $answerBuilder)
    {
    }

    public function handle(MessageStored $event): void
    {
        $message = $event->message->loadMissing(['profile', 'chat']);

        if ($message->type !== 'question') {
            return;
        }

        if (!$message->profile) {
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
