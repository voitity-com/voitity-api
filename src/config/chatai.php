<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Chat AI Service Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default chat AI driver that will be used to
    | interact with large language models throughout the application.
    |
    */

    'default' => env('CHAT_AI_DRIVER', 'openai'),

    /*
    |--------------------------------------------------------------------------
    | Audio Message Storage
    |--------------------------------------------------------------------------
    |
    | Uploaded audio messages are stored after successful transcription and the
    | public URL is persisted on the message record.
    |
    */

    'audio_messages' => [
        'disk' => env('CHAT_AUDIO_MESSAGES_DISK', 'public'),
        'folder' => env('CHAT_AUDIO_MESSAGES_FOLDER', 'messages/audio'),
        'visibility' => env('CHAT_AUDIO_MESSAGES_VISIBILITY', 'public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chat AI Service Drivers
    |--------------------------------------------------------------------------
    |
    | Here you may configure the chat AI drivers for your application. Each
    | driver may have its own configuration values that can be tweaked via
    | environment variables.
    |
    */

    'drivers' => [

        'openai' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'default_model' => env('OPENAI_DEFAULT_CHAT_MODEL', 'gpt-4o-mini'),
            'whisper_model' => env('OPENAI_DEFAULT_WHISPER_MODEL', 'whisper-1'),
        ],

        // Additional drivers may be configured here.
        // 'anthropic' => [
        //     'driver' => 'custom',
        //     'via' => fn () => app(\App\Classes\ChatAIService\Anthropic\AnthropicClient::class),
        // ],
    ],

];
