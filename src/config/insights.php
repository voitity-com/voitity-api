<?php

return [
    'chat_inactivity_minutes' => max(1, (int) env('CHAT_INACTIVITY_MINUTES', 30)),
    'max_range_months' => max(1, (int) env('INSIGHTS_MAX_RANGE_MONTHS', 24)),
    'visitor_hash_key' => env('INSIGHTS_VISITOR_HASH_KEY', env('APP_KEY')),
    'tracking_started_at' => env('INSIGHTS_TRACKING_STARTED_AT'),
    'query_warn_ms' => max(1, (int) env('INSIGHTS_QUERY_WARN_MS', 500)),
    'classification' => [
        'enabled' => filter_var(env('CONVERSATION_INSIGHTS_ENABLED', false), FILTER_VALIDATE_BOOL),
        'driver' => env('CONVERSATION_INSIGHTS_DRIVER', 'openai'),
        'model' => env('CONVERSATION_INSIGHTS_MODEL', 'gpt-4o-mini'),
        'confidence_threshold' => min(1, max(0, (float) env('CONVERSATION_INSIGHTS_CONFIDENCE_THRESHOLD', 0.65))),
        'prompt_version' => env('CONVERSATION_INSIGHTS_PROMPT_VERSION', 'v1'),
        'taxonomy_version' => env('CONVERSATION_INSIGHTS_TAXONOMY_VERSION', 'v1'),
        'max_messages' => max(10, (int) env('CONVERSATION_INSIGHTS_MAX_MESSAGES', 200)),
    ],
];
