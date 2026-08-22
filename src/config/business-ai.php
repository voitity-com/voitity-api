<?php

return [
    'decision' => [
        'driver' => env('BUSINESS_DECISION_AI_DRIVER', 'openai'),
        'model' => env('BUSINESS_DECISION_AI_MODEL', env('OPENAI_DEFAULT_CHAT_MODEL', 'gpt-4o-mini')),
        'minimum_confidence' => min(1, max(0, (float) env('BUSINESS_DECISION_MINIMUM_CONFIDENCE', 0.55))),
        'max_output_tokens' => max(100, (int) env('BUSINESS_DECISION_MAX_OUTPUT_TOKENS', 400)),
    ],

    'instruction' => [
        'driver' => env('BUSINESS_INSTRUCTION_AI_DRIVER', 'openai'),
        'model' => env('BUSINESS_INSTRUCTION_AI_MODEL', env('OPENAI_DEFAULT_CHAT_MODEL', 'gpt-4o-mini')),
        'max_output_tokens' => max(100, (int) env('BUSINESS_INSTRUCTION_MAX_OUTPUT_TOKENS', 500)),
    ],

    'problem' => [
        'driver' => env('BUSINESS_PROBLEM_AI_DRIVER', 'openai'),
        'model' => env('BUSINESS_PROBLEM_AI_MODEL', env('OPENAI_DEFAULT_CHAT_MODEL', 'gpt-4o-mini')),
        'max_output_tokens' => max(200, (int) env('BUSINESS_PROBLEM_MAX_OUTPUT_TOKENS', 900)),
    ],

    'knowledge' => [
        'chunk_characters' => max(500, (int) env('BUSINESS_KNOWLEDGE_CHUNK_CHARACTERS', 1800)),
        'chunk_overlap_characters' => max(0, (int) env('BUSINESS_KNOWLEDGE_CHUNK_OVERLAP_CHARACTERS', 180)),
        'embedding_batch_size' => max(1, (int) env('BUSINESS_KNOWLEDGE_EMBEDDING_BATCH_SIZE', 40)),
        'top_k' => max(1, (int) env('BUSINESS_KNOWLEDGE_TOP_K', 6)),
        'candidate_limit' => max(5, (int) env('BUSINESS_KNOWLEDGE_CANDIDATE_LIMIT', 30)),
        'minimum_score' => min(1, max(0, (float) env('BUSINESS_KNOWLEDGE_MINIMUM_SCORE', 0.32))),
        'max_context_tokens' => max(500, (int) env('BUSINESS_KNOWLEDGE_MAX_CONTEXT_TOKENS', 2600)),
    ],
];
