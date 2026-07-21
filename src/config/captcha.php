<?php

return [
    'enabled' => (bool) env('CAPTCHA_ENABLED', false),
    'driver' => env('CAPTCHA_DRIVER', 'turnstile'),

    'turnstile' => [
        'secret_key' => env('TURNSTILE_SECRET_KEY'),
        'siteverify_url' => env('TURNSTILE_SITEVERIFY_URL', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'),
        'timeout' => (int) env('TURNSTILE_TIMEOUT', 5),
    ],
];
