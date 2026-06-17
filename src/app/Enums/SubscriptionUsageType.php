<?php

namespace App\Enums;

enum SubscriptionUsageType: string
{
    case ProfileCreated = 'profile_created';
    case AvatarImageCreated = 'avatar_image_created';
    case AvatarVideoCreated = 'avatar_video_created';
    case VoiceCloned = 'voice_cloned';
    case VoiceTtsCharacters = 'voice_tts_characters';
    case ChatOpenAiCall = 'chat_openai_call';
}
