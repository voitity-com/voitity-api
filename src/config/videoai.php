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
    | Default Video AI Prompts
    |--------------------------------------------------------------------------
    |
    | These prompts mirror the Runway Postman collection and are used by the
    | queued avatar generation workflow when callers do not provide prompts.
    |
    */

    'prompts' => [
        'image' => 'Enhance the reference photo with a subtle beauty filter and keep it fully photorealistic. Preserve the person\'s exact identity, face shape, skin tone, age, ethnicity, expression, eyes, nose, lips, facial hair, hairstyle, clothing, pose, proportions, and framing. Gently remove acne, blemishes, redness, dark spots, excess shine, under-eye shadows, and temporary skin imperfections. Even the skin tone and soften skin slightly while preserving pores, fine details, and realistic texture. Improve lighting, color balance, sharpness, and clarity. Set the entire background to solid pure white (#FFFFFF), with no shadows, gradients, textures, or objects. Do not reshape the face, enlarge the eyes, alter the nose or lips, add makeup or facial hair, change skin color, expression, hairstyle, or add features. Avoid plastic skin, excessive smoothing, CGI, illustration, cartoon, Pixar, or 3D rendering. The result must look like the same real photo with a natural beauty filter.',
        'video' => 'Create a 2-second almost-still avatar clip from this portrait. Preserve identity, face shape, expression, outfit, framing, and the clean white background exactly. Lock the head, neck, shoulders, torso, hair, mouth, lips, cheeks, jaw, eyebrows, camera, lighting, and background. The only allowed motion is one natural blink and a very subtle eye gaze shift to one side, then back toward camera. No head movement, no body movement, no shoulder movement, no breathing motion, no mouth movement, no lip motion, no jaw movement, no smile, no grin, no smirk, no teeth, no talking, no lip-sync, no expression change, no camera movement, no zoom, no lighting change, no background change, and no gestures. Smooth micro-motion only; avoid any sudden or exaggerated movement.',
    ],

    /*
    |--------------------------------------------------------------------------
    | Profile Artifact Storage
    |--------------------------------------------------------------------------
    |
    | Generated avatar assets are public profile artifacts. In production this
    | disk points to the dedicated profiles S3 bucket.
    |
    */

    'profiles' => [
        'disk' => env('VIDEOAI_PROFILES_DISK', 'profiles'),
        'image_folder' => env('VIDEOAI_PROFILES_IMAGE_FOLDER', 'images'),
        'source_image_folder' => env('VIDEOAI_PROFILES_SOURCE_IMAGE_FOLDER', 'images/sources'),
        'video_folder' => env('VIDEOAI_PROFILES_VIDEO_FOLDER', 'videos'),
    ],

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
            'default_duration' => (int) env('RUNWAY_DEFAULT_DURATION', 2),
        ],

        // Additional drivers may be configured here.
        // 'custom-provider' => [
        //     'driver' => 'custom',
        //     'via' => fn () => app(\App\Classes\VideoAIService\SomeProvider\SomeProviderVideoAI::class),
        // ],

    ],

];
