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
        'video' => 'Create a 2-second avatar clip from this portrait. Preserve identity, face shape, outfit, framing, and the clean white background exactly. Keep the avatar almost still. The only motion allowed is one natural blink and a very slight head tilt, then return to the original pose. No shoulder movement, no breathing motion, no eye darting, no facial expression change, no smile change, no mouth movement, no talking, no lip-sync, no teeth, no camera movement, no zoom, no lighting change, no background change, and no added gestures. Smooth minimal motion only.',
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
