<?php

namespace App\Jobs;

use App\Classes\ChatAIService\AnswerBuilder;
use App\Events\MessageStored;
use App\Listeners\ProcessStoredMessage;
use App\Models\Message;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessStoredMessageJob implements ShouldQueueAfterCommit
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(public readonly int $messageId)
    {
        $this->onQueue((string) config('queue.workloads.chat', 'chat'));
    }

    public function handle(AnswerBuilder $answerBuilder): void
    {
        $message = Message::query()->find($this->messageId);

        if (! $message instanceof Message) {
            return;
        }

        (new ProcessStoredMessage($answerBuilder))->process(new MessageStored($message));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 30, 120];
    }
}
