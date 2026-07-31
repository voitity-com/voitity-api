<?php

return [
    'recipient_email' => env('SUPPORT_RECIPIENT_EMAIL', 'support@bigmelo.com'),
    'recipient_name' => env('SUPPORT_RECIPIENT_NAME', 'Bigmelo Support'),
    'notification_subject' => env('SUPPORT_NOTIFICATION_SUBJECT', 'Nueva solicitud de soporte en Bigmelo'),
    'rate_limit_per_minute' => (int) env('SUPPORT_RATE_LIMIT_PER_MINUTE', 5),
];
