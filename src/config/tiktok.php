<?php

return [
    'client_key' => env('TIKTOK_CLIENT_KEY'),
    'client_secret' => env('TIKTOK_CLIENT_SECRET'),
    'redirect_uri' => env('TIKTOK_REDIRECT_URI'),
    'scopes' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('TIKTOK_SCOPES', 'user.info.basic,video.list'))
    ))),
    'auth_url' => env('TIKTOK_AUTH_URL', 'https://www.tiktok.com/v2/auth/authorize/'),
    'token_url' => env('TIKTOK_TOKEN_URL', 'https://open.tiktokapis.com/v2/oauth/token/'),
    'revoke_url' => env('TIKTOK_REVOKE_URL', 'https://open.tiktokapis.com/v2/oauth/revoke/'),
    'api_base_url' => rtrim(env('TIKTOK_API_BASE_URL', 'https://open.tiktokapis.com'), '/'),
    'admin_redirect_url' => rtrim(env('ADMIN_APP_URL', env('MAIL_ADMIN_URL', 'http://localhost:3000')), '/'),
    'oauth_state_ttl_minutes' => (int) env('TIKTOK_OAUTH_STATE_TTL_MINUTES', 10),
    'pkce_enabled' => env('TIKTOK_PKCE_ENABLED'),
    'media_limit' => (int) env('TIKTOK_MEDIA_LIMIT', 100),
    'selection_limit' => (int) env('TIKTOK_MEDIA_SELECTION_LIMIT', 10),
    'refresh_leeway_minutes' => (int) env('TIKTOK_REFRESH_LEEWAY_MINUTES', 10),
];
