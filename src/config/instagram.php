<?php

return [
    'client_id' => env('INSTAGRAM_CLIENT_ID'),
    'client_secret' => env('INSTAGRAM_CLIENT_SECRET'),
    'redirect_uri' => env('INSTAGRAM_REDIRECT_URI'),
    'scopes' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('INSTAGRAM_SCOPES', 'instagram_business_basic'))
    ))),
    'auth_url' => env('INSTAGRAM_AUTH_URL', 'https://www.instagram.com/oauth/authorize'),
    'token_url' => env('INSTAGRAM_TOKEN_URL', 'https://api.instagram.com/oauth/access_token'),
    'graph_base_url' => rtrim(env('INSTAGRAM_GRAPH_BASE_URL', 'https://graph.instagram.com'), '/'),
    'graph_api_version' => trim(env('INSTAGRAM_GRAPH_API_VERSION', 'v25.0'), '/'),
    'long_lived_token_url' => env('INSTAGRAM_LONG_LIVED_TOKEN_URL', 'https://graph.instagram.com/access_token'),
    'admin_redirect_url' => rtrim(env('ADMIN_APP_URL', env('MAIL_ADMIN_URL', 'http://localhost:3000')), '/'),
    'enable_fb_login' => (bool) env('INSTAGRAM_ENABLE_FB_LOGIN', false),
    'force_reauth' => (bool) env('INSTAGRAM_FORCE_REAUTH', env('INSTAGRAM_FORCE_AUTHENTICATION', true)),
    'oauth_state_ttl_minutes' => (int) env('INSTAGRAM_OAUTH_STATE_TTL_MINUTES', 10),
    'media_limit' => (int) env('INSTAGRAM_MEDIA_LIMIT', 100),
    'selection_limit' => (int) env('INSTAGRAM_MEDIA_SELECTION_LIMIT', 10),
];
