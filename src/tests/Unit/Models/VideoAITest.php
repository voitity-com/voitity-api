<?php

namespace Tests\Unit\Models;

use App\Models\Profile;
use App\Models\User;
use App\Models\VideoAI;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VideoAITest extends TestCase
{
    #[Test]
    public function video_ais_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('video_ais'));

        $this->assertTrue(Schema::hasColumns('video_ais', [
            'id',
            'user_id',
            'profile_id',
            'source_id',
            'source',
            'file',
            'deleted_at',
            'created_at',
            'updated_at',
        ]));
    }

    #[Test]
    public function video_ai_belongs_to_user_and_optional_profile(): void
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

        $videoAI = VideoAI::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source_id' => 'runway-task-id',
            'source' => 'runway',
            'file' => 'videos/runway-task-id.mp4',
        ]);

        $this->assertTrue($videoAI->user->is($user));
        $this->assertTrue($videoAI->profile->is($profile));
        $this->assertTrue($user->videoAIs()->first()->is($videoAI));
        $this->assertTrue($profile->videoAIs()->first()->is($videoAI));
    }

    #[Test]
    public function video_ai_can_be_created_without_profile(): void
    {
        $user = User::factory()->create();

        $videoAI = VideoAI::create([
            'user_id' => $user->id,
            'profile_id' => null,
            'source_id' => 'runway-task-id',
            'source' => 'runway',
            'file' => 'videos/runway-task-id.mp4',
        ]);

        $this->assertNull($videoAI->profile_id);
        $this->assertNull($videoAI->profile);
    }
}
