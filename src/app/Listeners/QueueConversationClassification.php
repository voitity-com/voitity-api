<?php

namespace App\Listeners;

use App\Events\ChatClosed;
use App\Jobs\Insights\ClassifyConversation;

class QueueConversationClassification
{
    public function handle(ChatClosed $event): void
    {
        if ((bool) config('insights.classification.enabled', true)) {
            ClassifyConversation::dispatch($event->chat->id)->afterCommit();
        }
    }
}
