<?php

use App\Providers\AppServiceProvider;
use App\Providers\ChatAIServiceProvider;
use App\Providers\ConversationInsightsServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\PaymentServiceProvider;
use App\Providers\ProfileKnowledgeAIServiceProvider;
use App\Providers\UsdCopRateServiceProvider;
use App\Providers\VideoAIServiceProvider;
use App\Providers\VoiceSampleServiceProvider;
use App\Providers\VoiceServiceProvider;
use App\Providers\YouTubeServiceProvider;

return [
    AppServiceProvider::class,
    EventServiceProvider::class,
    ChatAIServiceProvider::class,
    ConversationInsightsServiceProvider::class,
    ProfileKnowledgeAIServiceProvider::class,
    VoiceSampleServiceProvider::class,
    VoiceServiceProvider::class,
    VideoAIServiceProvider::class,
    UsdCopRateServiceProvider::class,
    PaymentServiceProvider::class,
    YouTubeServiceProvider::class,
];
