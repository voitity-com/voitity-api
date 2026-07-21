<?php

return [
    'recipient_email' => env('CONTACT_RECIPIENT_EMAIL', env('MAIL_SUPPORT_EMAIL', env('MAIL_FROM_ADDRESS'))),
    'recipient_name' => env('CONTACT_RECIPIENT_NAME', env('APP_NAME', 'Bigmelo')),
    'notification_subject' => env('CONTACT_NOTIFICATION_SUBJECT', 'Nuevo contacto desde Bigmelo'),
    'rate_limit_per_minute' => (int) env('CONTACT_RATE_LIMIT_PER_MINUTE', 5),
];
