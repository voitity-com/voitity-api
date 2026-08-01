<?php

namespace Tests\Feature\Insights;

use App\Enums\ChatStatus;
use App\Events\ChatClosed;
use App\Models\Chat;
use App\Models\Profile;
use App\Services\Insights\ChatLifecycleService;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ChatLifecycleServiceTest extends TestCase
{
    public function test_message_after_inactivity_closes_old_chat_and_creates_new_chat(): void
    {
        Event::fake([ChatClosed::class]);
        config(['insights.chat_inactivity_minutes' => 30]);
        $profile = Profile::factory()->create();
        $old = Chat::query()->create([
            'profile_id' => $profile->id,
            'status' => ChatStatus::Open,
            'started_at' => now()->subHour(),
            'last_activity_at' => now()->subMinutes(31),
            'visitor_id_hash' => 'visitor-a',
        ]);

        [$next, $isNew] = app(ChatLifecycleService::class)->resolve($profile, $old->id, 'visitor-a');

        $this->assertTrue($isNew);
        $this->assertNotSame($old->id, $next->id);
        $this->assertSame(ChatStatus::Closed, $old->fresh()->status);
        $this->assertSame(ChatStatus::Open, $next->status);
        Event::assertDispatched(ChatClosed::class, fn (ChatClosed $event): bool => $event->chat->id === $old->id);
    }

    public function test_scheduled_closer_is_idempotent(): void
    {
        Event::fake([ChatClosed::class]);
        $profile = Profile::factory()->create();
        Chat::query()->create([
            'profile_id' => $profile->id,
            'status' => ChatStatus::Open,
            'started_at' => now()->subHour(),
            'last_activity_at' => now()->subMinutes(31),
        ]);
        $service = app(ChatLifecycleService::class);

        $this->assertSame(1, $service->closeInactive());
        $this->assertSame(0, $service->closeInactive());
        Event::assertDispatchedTimes(ChatClosed::class, 1);
    }
}
