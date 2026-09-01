<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Reserved public profile aliases
    |--------------------------------------------------------------------------
    |
    | These first path segments belong to Bigmelo pages and must never be
    | claimed by a public profile. Validation is case-insensitive.
    |
    */
    'restricted_aliases' => [
        'landing',
        'privacidad',
        'privacy',
        'terminos',
        'terms',
        'eliminacion-datos',
        'eliminacion-de-datos',
        'data-deletion',
        'user-data-deletion',
    ],

    'chat_session_lifetime_minutes' => (int) env(
        'PUBLIC_PROFILE_CHAT_SESSION_LIFETIME_MINUTES',
        1440,
    ),
    'read_rate_limit_per_minute' => (int) env(
        'PUBLIC_PROFILE_READ_RATE_LIMIT_PER_MINUTE',
        120,
    ),
];
