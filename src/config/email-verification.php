<?php

return [
    'expires_in_minutes' => (int) env('EMAIL_VERIFICATION_EXPIRES_IN_MINUTES', 1440),
    'redirect_url' => env('EMAIL_VERIFICATION_REDIRECT_URL', 'http://localhost:3000/auth/custom/sign-in'),
];
