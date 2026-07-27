<?php

return [
    'disk' => env('ONLYFANS_MEDIA_DISK', 'profiles'),
    'folder' => trim((string) env('ONLYFANS_MEDIA_FOLDER', 'integrations/onlyfans'), '/'),
    'visibility' => env('ONLYFANS_MEDIA_VISIBILITY', 'public'),
    'selection_limit' => (int) env('ONLYFANS_MEDIA_SELECTION_LIMIT', 10),
    'max_image_size_mb' => (int) env('ONLYFANS_MAX_IMAGE_SIZE_MB', 10),
    'max_video_size_mb' => (int) env('ONLYFANS_MAX_VIDEO_SIZE_MB', 100),
    'profile_base_url' => rtrim((string) env('ONLYFANS_PROFILE_BASE_URL', 'https://onlyfans.com'), '/'),
];
