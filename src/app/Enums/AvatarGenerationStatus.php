<?php

namespace App\Enums;

enum AvatarGenerationStatus: string
{
    case Completed = 'completed';
    case ImageFailed = 'image_failed';
    case Processing = 'processing';
    case VideoFailed = 'video_failed';
}
