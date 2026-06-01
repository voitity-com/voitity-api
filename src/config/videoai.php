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
        'image' => 'EDITABLE PROMPT: Using the reference image tagged base_image, create a Pixar-like but hyperrealistic high-end 3D animated portrait. Preserve the original person identity, facial structure, skin tone, hair, eyes, expression, pose, clothing, proportions, and overall appearance as much as possible. Apply a subtle Instagram-style beauty retouch filter: reduce facial blemishes, acne, redness, shine, and under-eye shadows while keeping natural skin texture. Use a completely pure white seamless studio background (#FFFFFF). Add soft flattering studio lighting, realistic eyes, detailed hair, natural face shape, and polished cinematic 3D character rendering. Do not change age, ethnicity, face geometry, hairstyle, clothing colors, body shape, or add accessories. Avoid distorted features, heavy makeup, plastic skin, and exaggerated cartoon proportions.',
        'video' => 'Create a seamless 5-second loop from this generated portrait. The first frame and final frame must match the same pose, expression, eye direction, head position, background, lighting, and camera framing so the replay loop has no visible cut. Keep identity, face, hair, clothing, and pure white background locked. Add only very subtle natural motion: slow gentle eye movement, one soft blink if needed, and a faint closed-mouth smile that slowly appears and returns to the starting neutral expression before the final frame. Lips must stay closed at all times. Do not open the mouth, do not show teeth, do not speak, do not look like talking, no head movement, no body movement, no camera movement, no zoom, no scene change, no exaggerated expression. Smooth minimal motion only.',
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
            'default_duration' => (int) env('RUNWAY_DEFAULT_DURATION', 5),
        ],

        // Additional drivers may be configured here.
        // 'custom-provider' => [
        //     'driver' => 'custom',
        //     'via' => fn () => app(\App\Classes\VideoAIService\SomeProvider\SomeProviderVideoAI::class),
        // ],

    ],

];
