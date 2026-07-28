<?php

return [
    'disk' => env('PRODUCTS_IMAGE_DISK', 'profiles'),
    'folder' => trim((string) env('PRODUCTS_IMAGE_FOLDER', 'products'), '/'),
    'visibility' => env('PRODUCTS_IMAGE_VISIBILITY', 'public'),
    'max_products' => (int) env('PRODUCTS_MAX_PER_PROFILE', 15),
    'max_image_size_mb' => (int) env('PRODUCTS_MAX_IMAGE_SIZE_MB', 10),
    'message_description_limit' => (int) env('PRODUCTS_MESSAGE_DESCRIPTION_LIMIT', 120),
    'csv_max_size_kb' => (int) env('PRODUCTS_CSV_MAX_SIZE_KB', 5120),
    'csv_max_rows' => (int) env('PRODUCTS_CSV_MAX_ROWS', 500),
    'public_base_url' => rtrim((string) env('PRODUCTS_PUBLIC_BASE_URL', env('APP_URL').'/p'), '/'),
    'prompt_limit' => (int) env('PRODUCTS_PROMPT_LIMIT', 15),
    'social_image_width' => (int) env('PRODUCTS_SOCIAL_IMAGE_WIDTH', 1200),
    'social_image_height' => (int) env('PRODUCTS_SOCIAL_IMAGE_HEIGHT', 630),
    'social_image_quality' => (int) env('PRODUCTS_SOCIAL_IMAGE_QUALITY', 88),
    'social_image_max_pixels' => (int) env('PRODUCTS_SOCIAL_IMAGE_MAX_PIXELS', 20_000_000),
];
