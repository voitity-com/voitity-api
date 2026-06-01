<?php

namespace Tests\Unit\Models;

use App\Models\Profile;
use App\Models\User;
use App\Models\AiImage;
use App\Models\AiVideo;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiVideoTest extends TestCase
{
    #[Test]
    public function aivideos_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('aivideos'));

        $this->assertTrue(Schema::hasColumns('aivideos', [
            'id',
            'user_id',
            'profile_id',
            'aiimage_id',
            'source_id',
            'source',
            'status',
            'file',
            'deleted_at',
            'created_at',
            'updated_at',
        ]));
    }

    #[Test]
    public function ai_video_belongs_to_user_and_optional_profile(): void
    {
        $user = User::factory()->create();
        $profile = Profile::create([
            'user_id' => $user->id,
            'name' => 'Test profile',
            'description' => 'Test description',
            'genre' => 'test',
            'personality' => 'friendly',
            'active' => true,
        ]);
        $aiImage = AiImage::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source_id' => 'runway-image-task-id',
            'source' => 'runway',
            'status' => 'succeeded',
            'file' => 'images/runway-image-task-id.png',
        ]);

        $aiVideo = AiVideo::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'aiimage_id' => $aiImage->id,
            'source_id' => 'runway-task-id',
            'source' => 'runway',
            'status' => 'succeeded',
            'file' => 'videos/runway-task-id.mp4',
        ]);

        $this->assertSame('succeeded', $aiVideo->status);
        $this->assertTrue($aiVideo->user->is($user));
        $this->assertTrue($aiVideo->profile->is($profile));
        $this->assertTrue($aiVideo->aiImage->is($aiImage));
        $this->assertTrue($user->aiVideos()->first()->is($aiVideo));
        $this->assertTrue($profile->aiVideos()->first()->is($aiVideo));
        $this->assertTrue($aiImage->aiVideos()->first()->is($aiVideo));
    }

    #[Test]
    public function ai_video_can_be_created_without_profile(): void
    {
        $user = User::factory()->create();

        $aiVideo = AiVideo::create([
            'user_id' => $user->id,
            'profile_id' => null,
            'source_id' => 'runway-task-id',
            'source' => 'runway',
            'file' => 'videos/runway-task-id.mp4',
        ]);
        $aiVideo->refresh();

        $this->assertSame('pending', $aiVideo->status);
        $this->assertNull($aiVideo->profile_id);
        $this->assertNull($aiVideo->profile);
    }
}
