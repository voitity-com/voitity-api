<?php

namespace App\Events;

use App\Models\Chat;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatClosed
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Chat $chat) {}
}
