<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Voice Service Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default voice service driver that will be used
    | to process voice cloning, voice sample addition, and audio generation.
    | You may set this to any of the drivers defined in the "drivers" array.
    |
    */

    'default' => env('VOICE_DRIVER', 'elevenlabs'),

    /*
    |--------------------------------------------------------------------------
    | Voice Service Drivers
    |--------------------------------------------------------------------------
    |
    | Here you may configure the voice service drivers for your application.
    | Each driver has its own configuration options which you may adjust
    | based on your needs.
    |
    */

    'drivers' => [

        'elevenlabs' => [
            'driver' => 'elevenlabs',
            'base_url'=> env('VOICE_DRIVERS_ELEVENLABS_BASE_URL', 'https://api.elevenlabs.io'),
            'api_key' => env('VOICE_DRIVERS_ELEVENLABS_API_KEY'),
            'model_id' => env('VOICE_DRIVERS_ELEVENLABS_MODEL_ID', 'eleven_multilingual_v2'),
            'default_voice_settings' => [
                'stability' => 0.75,
                'similarity_boost' => 1.0,
                'style' => 0,
            ],
        ],

        // Add other drivers here as needed
        // 'openai' => [
        //     'driver' => 'openai',
        // ],
        //
        // 'aws-polly' => [
        //     'driver' => 'aws-polly',
        // ],

    ],

];
