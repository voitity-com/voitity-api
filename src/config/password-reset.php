<?php

return [
    'expires_in_minutes' => (int) env('PASSWORD_RESET_EXPIRES_IN_MINUTES', 60),
    'redirect_url' => env('PASSWORD_RESET_REDIRECT_URL', 'http://localhost:3000/auth/custom/reset-password'),
];
