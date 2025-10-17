<?php

namespace App\Events;

use App\Classes\ChatAIService\AnswerResponse;
use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageStored
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The stored message instance.
     */
    public Message $message;

    /**
     * The built answer result populated by the listener.
     */
    public ?AnswerResponse $answer = null;

    public function __construct(Message $message)
    {
        $this->message = $message;
    }
}
