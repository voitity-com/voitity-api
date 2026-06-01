<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Video AI Service Driver
    |--------------------------------------------------------------------------
    |
    | This option controls the default video AI driver that will be used to
    | generate images, generate videos, and query provider task status.
    |
    */

    'default' => env('VIDEOAI_DRIVER', 'runway'),

    /*
    |--------------------------------------------------------------------------
    | Video AI Service Drivers
    |--------------------------------------------------------------------------
    |
    | Each driver may define its own API credentials, model defaults, and
    | endpoint settings. The Runway defaults mirror the Postman collection.
    |
    */

    'drivers' => [

        'runway' => [
            'driver' => 'runway',
            'api_key' => env('RUNWAY_API_KEY'),
            'base_url' => env('RUNWAY_BASE_URL', 'https://api.dev.runwayml.com'),
            'api_version' => env('RUNWAY_API_VERSION', '2024-11-06'),
            'image_model' => env('RUNWAY_IMAGE_MODEL', 'gen4_image'),
            'video_model' => env('RUNWAY_VIDEO_MODEL', 'gen4.5'),
            'reference_image_tag' => env('RUNWAY_REFERENCE_IMAGE_TAG', 'base_image'),
            'default_image_ratio' => env('RUNWAY_DEFAULT_IMAGE_RATIO', '1024:1024'),
            'default_video_ratio' => env('RUNWAY_DEFAULT_VIDEO_RATIO', '960:960'),
            'default_duration' => (int) env('RUNWAY_DEFAULT_DURATION', 5),
        ],

        // Additional drivers may be configured here.
        // 'custom-provider' => [
        //     'driver' => 'custom',
        //     'via' => fn () => app(\App\Classes\VideoAIService\SomeProvider\SomeProviderVideoAI::class),
        // ],

    ],

];
