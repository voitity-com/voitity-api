<?php

namespace App\Providers;

use App\Events\Voices\VoiceSampleAdded;
use App\Listeners\Voices\CloneVoice;
use App\Listeners\Voices\AddSample;
use App\Events\MessageStored;
use App\Listeners\ProcessStoredMessage;
use App\Events\AI\Images\AiImageForAvatarCreated;
use App\Events\AI\Images\AiImageForAvatarGenerated;
use App\Events\AI\Videos\AiVideoForAvatarCreated;
use App\Listeners\AI\Images\GetAIImageForAvatar;
use App\Listeners\AI\Videos\CreateAiVideoForAvatar;
use App\Listeners\AI\Videos\GetAIVideoForAvatar;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        MessageStored::class => [
            ProcessStoredMessage::class,
        ],
        VoiceSampleAdded::class => [
            CloneVoice::class,
            AddSample::class,
        ],
        AiImageForAvatarCreated::class => [
            GetAIImageForAvatar::class,
        ],
        AiImageForAvatarGenerated::class => [
            CreateAiVideoForAvatar::class,
        ],
        AiVideoForAvatarCreated::class => [
            GetAIVideoForAvatar::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot(): void
    {
        //
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     *
     * @return bool
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
