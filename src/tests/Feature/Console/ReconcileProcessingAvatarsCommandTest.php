<?php

namespace Tests\Feature\Console;

use App\Events\AI\Images\AiImageForAvatarCreated;
use App\Events\AI\Images\AiImageForAvatarGenerated;
use App\Events\AI\Videos\AiVideoForAvatarCreated;
use App\Models\AiImage;
use App\Models\AiVideo;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ReconcileProcessingAvatarsCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_requeues_the_existing_video_for_a_stale_processing_avatar(): void
    {
        Carbon::setTestNow('2026-09-01 20:30:00');
        Event::fake([AiVideoForAvatarCreated::class]);
        [$avatar, $aiImage] = $this->processingAvatar(now()->subMinutes(20));
        $aiVideo = AiVideo::create([
            'user_id' => $avatar->user_id,
            'profile_id' => $avatar->profile_id,
            'aiimage_id' => $aiImage->id,
            'source_id' => 'runway-video-id',
            'source' => 'runway',
            'status' => 'running',
        ]);

        $this->artisan('avatars:reconcile-processing')
            ->expectsOutput('Stale avatar generations requeued: 1; skipped: 0; failed: 0.')
            ->assertSuccessful();

        Event::assertDispatched(
            AiVideoForAvatarCreated::class,
            fn (AiVideoForAvatarCreated $event): bool => $event->aiVideo->is($aiVideo)
                && $event->aiImage?->is($aiImage)
        );
        $this->assertTrue($avatar->updated_at->lt($avatar->fresh()->updated_at));
    }

    public function test_it_requeues_the_image_stage_when_no_video_has_been_created(): void
    {
        Carbon::setTestNow('2026-09-01 20:30:00');
        Event::fake([AiImageForAvatarGenerated::class]);
        [$avatar, $aiImage] = $this->processingAvatar(now()->subMinutes(20));

        $this->artisan('avatars:reconcile-processing')
            ->assertSuccessful();

        Event::assertDispatched(
            AiImageForAvatarGenerated::class,
            fn (AiImageForAvatarGenerated $event): bool => $event->aiImage->is($aiImage)
                && $event->sourceImageUrl === $aiImage->file
        );
    }

    public function test_it_ignores_processing_avatars_that_are_not_stale(): void
    {
        Carbon::setTestNow('2026-09-01 20:30:00');
        Event::fake([
            AiImageForAvatarCreated::class,
            AiImageForAvatarGenerated::class,
            AiVideoForAvatarCreated::class,
        ]);
        $this->processingAvatar(now()->subMinutes(5));

        $this->artisan('avatars:reconcile-processing')
            ->expectsOutput('Stale avatar generations requeued: 0; skipped: 0; failed: 0.')
            ->assertSuccessful();

        Event::assertNothingDispatched();
    }

    public function test_the_reconciler_schedule_is_single_server_and_non_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())->first(
            fn ($event): bool => str_contains((string) $event->command, 'avatars:reconcile-processing')
        );

        $this->assertNotNull($event);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->onOneServer);
        $this->assertSame('*/5 * * * *', $event->expression);
    }

    /**
     * @return array{ProfileAvatar, AiImage}
     */
    private function processingAvatar(Carbon $updatedAt): array
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Recovery profile',
            'description' => 'Recovery test profile',
            'genre' => 'fitness',
            'personality' => 'friendly',
            'active' => true,
        ]);
        $aiImage = AiImage::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source_id' => 'runway-image-id',
            'source' => 'runway',
            'status' => 'succeeded',
            'file' => 'https://example.com/avatar.png',
        ]);
        $avatar = ProfileAvatar::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'aiimage_id' => $aiImage->id,
            'status' => ProfileAvatar::STATUS_PROCESSING,
        ]);
        $avatar->forceFill(['updated_at' => $updatedAt])->saveQuietly();

        return [$avatar->fresh(), $aiImage];
    }
}
