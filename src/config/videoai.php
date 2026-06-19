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
        'image' => 'Using reference image base_image, create a Pixar-like hyperrealistic 3D portrait. Preserve identity, face shape, skin tone, hair, eyes, expression, pose, clothing, proportions, age, ethnicity, hairstyle, and colors. Match the original facial traits exactly. Do not add, invent, enlarge, darken, thicken, enhance, or exaggerate any feature. If source has no beard, keep face clean-shaven. Never add or amplify beard, mustache, stubble, beard shadow, goatee, sideburns, facial hair texture, dark patches on cheeks/chin, extra hair, hairline, eyebrows, scars, accessories, makeup, or new details. Apply subtle retouch: reduce blemishes, acne, redness, wrinkles, facial shine, and under-eye shadows; keep natural skin texture. Smooth contours without blurring identity. Use pure white background (#FFFFFF), soft studio lighting, realistic eyes, detailed hair, natural face shape, cinematic 3D rendering. Avoid distorted features, plastic skin, heavy makeup, cartoon proportions.',
        'video' => 'Create a nearly static 5-second loop from this portrait. Preserve the exact original expression from the first frame to the last frame. Neutral face only: no smile, no laugh, no grin, no smirk, no happy expression, no exaggerated expression. The mouth and lips must remain fully closed, sealed, still, and unchanged for the entire video. No lip motion, no mouth opening, no teeth, no jaw movement, no talking, no lip-sync, no speech-like motion. Animate only one very tiny natural blink. No head movement, no body movement, no camera movement, no zoom, no lighting change, no background change. First and final frame must match exactly for a seamless loop.',
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
            'default_duration' => (int) env('RUNWAY_DEFAULT_DURATION', 3),
        ],

        // Additional drivers may be configured here.
        // 'custom-provider' => [
        //     'driver' => 'custom',
        //     'via' => fn () => app(\App\Classes\VideoAIService\SomeProvider\SomeProviderVideoAI::class),
        // ],

    ],

];
