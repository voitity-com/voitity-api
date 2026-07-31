<?php

return [
    'chat_session_lifetime_minutes' => (int) env(
        'PUBLIC_PROFILE_CHAT_SESSION_LIFETIME_MINUTES',
        1440,
    ),
    'read_rate_limit_per_minute' => (int) env(
        'PUBLIC_PROFILE_READ_RATE_LIMIT_PER_MINUTE',
        120,
    ),
];
