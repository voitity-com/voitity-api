<?php

namespace App\Services\Notifications;

class NotificationMessage
{
    public function __construct(
        public readonly string $subject,
        public readonly string $title,
        public readonly string $body,
        public readonly ?string $actionLabel,
        public readonly ?string $actionUrl,
    ) {}
}
