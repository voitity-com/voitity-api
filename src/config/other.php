<?php

return [
    'disk' => env('OTHER_MEDIA_DISK', 'profiles'),
    'folder' => trim((string) env('OTHER_MEDIA_FOLDER', 'integrations/other'), '/'),
    'visibility' => env('OTHER_MEDIA_VISIBILITY', 'public'),
    'selection_limit' => (int) env('OTHER_MEDIA_SELECTION_LIMIT', 10),
    'max_image_size_mb' => (int) env('OTHER_MAX_IMAGE_SIZE_MB', 10),
    'max_video_size_mb' => (int) env('OTHER_MAX_VIDEO_SIZE_MB', 100),
];
