<?php

return [
    'default' => env('YOUTUBE_DRIVER', 'google'),
    'selection_limit' => (int) env('YOUTUBE_MEDIA_SELECTION_LIMIT', 10),
    'metadata_refresh_days' => (int) env('YOUTUBE_METADATA_REFRESH_DAYS', 25),
    'drivers' => [
        'google' => [
            'api_key' => env('YOUTUBE_API_KEY'),
            'base_url' => env('YOUTUBE_API_BASE_URL', 'https://www.googleapis.com/youtube/v3'),
            'timeout' => (int) env('YOUTUBE_API_TIMEOUT', 10),
        ],
    ],
];
