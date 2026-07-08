<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Profile Knowledge AI Structuring
    |--------------------------------------------------------------------------
    |
    | CV and document imports can be normalized through a provider adapter.
    | The local driver is deterministic and safe for tests. Enable the remote
    | driver when structured extraction should use an LLM provider.
    |
    */

    'enabled' => env('PROFILE_KNOWLEDGE_AI_ENABLED', false),

    'default' => env('PROFILE_KNOWLEDGE_AI_DRIVER', 'openai'),

    'fallback_driver' => env('PROFILE_KNOWLEDGE_AI_FALLBACK_DRIVER', 'local'),

    'sources' => [
        'disk' => env('PROFILE_SOURCES_DISK', 'profiles'),
        'folder' => env('PROFILE_SOURCES_FOLDER', 'sources'),
        'visibility' => env('PROFILE_SOURCES_VISIBILITY', 'private'),
    ],

    'drivers' => [
        'openai' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'default_model' => env('PROFILE_KNOWLEDGE_OPENAI_MODEL', env('OPENAI_DEFAULT_CHAT_MODEL', 'gpt-4o-mini')),
            'max_tokens' => env('PROFILE_KNOWLEDGE_OPENAI_MAX_TOKENS', 3500),
            'temperature' => env('PROFILE_KNOWLEDGE_OPENAI_TEMPERATURE', 0.1),
        ],

        'local' => [
            'driver' => 'local',
        ],

        // Additional drivers may be configured here.
        // 'custom' => [
        //     'driver' => 'custom',
        //     'via' => fn () => app(\App\Classes\ProfileKnowledgeAIService\SomeProvider\SomeProviderClient::class),
        // ],
    ],

];
