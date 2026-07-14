<?php

return [
    'defaults' => [
        'initial_audio_url' => env('PROFILE_CONVERSATION_INITIAL_AUDIO_URL'),
    ],

    'audio' => [
        'disk' => env('PROFILE_CONVERSATION_AUDIO_DISK', env('VOICE_GENERATED_AUDIO_DISK', 'public')),
        'folder' => env('PROFILE_CONVERSATION_AUDIO_FOLDER', 'profile-conversation'),
        'visibility' => env('PROFILE_CONVERSATION_AUDIO_VISIBILITY', env('VOICE_GENERATED_AUDIO_VISIBILITY', 'public')),
    ],
];
