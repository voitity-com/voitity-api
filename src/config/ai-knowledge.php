<?php

return [
    'indexing' => [
        'version' => env('AI_KNOWLEDGE_INDEX_VERSION', '2026-08-06-v2'),
        'batch_size' => max(1, (int) env('AI_KNOWLEDGE_EMBEDDING_BATCH_SIZE', 50)),
        'raw_source_chunk_characters' => max(500, (int) env('AI_KNOWLEDGE_RAW_SOURCE_CHUNK_CHARACTERS', 2400)),
    ],

    'embedding' => [
        'default' => env('AI_KNOWLEDGE_EMBEDDING_DRIVER', 'openai'),
        'model' => env('AI_KNOWLEDGE_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'dimensions' => max(1, (int) env('AI_KNOWLEDGE_EMBEDDING_DIMENSIONS', 1536)),
        'drivers' => [
            'openai' => [
                'driver' => 'openai',
                'api_key' => env('OPENAI_API_KEY'),
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
                'model' => env('AI_KNOWLEDGE_EMBEDDING_MODEL', 'text-embedding-3-small'),
                'dimensions' => max(1, (int) env('AI_KNOWLEDGE_EMBEDDING_DIMENSIONS', 1536)),
                'retry_attempts' => max(1, (int) env('AI_KNOWLEDGE_EMBEDDING_RETRY_ATTEMPTS', 3)),
                'retry_delay_ms' => max(0, (int) env('AI_KNOWLEDGE_EMBEDDING_RETRY_DELAY_MS', 250)),
            ],
        ],
    ],

    'retrieval' => [
        'top_k' => max(1, (int) env('AI_KNOWLEDGE_RETRIEVAL_TOP_K', 8)),
        'candidate_limit' => max(5, (int) env('AI_KNOWLEDGE_RETRIEVAL_CANDIDATE_LIMIT', 40)),
        'lexical_candidate_limit' => max(5, (int) env('AI_KNOWLEDGE_RETRIEVAL_LEXICAL_CANDIDATE_LIMIT', 30)),
        'minimum_score' => min(1, max(0, (float) env('AI_KNOWLEDGE_RETRIEVAL_MINIMUM_SCORE', 0.35))),
        'max_context_tokens' => max(500, (int) env('AI_KNOWLEDGE_MAX_CONTEXT_TOKENS', 2500)),
        'proactive_media_enabled' => filter_var(env('AI_KNOWLEDGE_PROACTIVE_MEDIA_ENABLED', false), FILTER_VALIDATE_BOOL),
    ],
];
