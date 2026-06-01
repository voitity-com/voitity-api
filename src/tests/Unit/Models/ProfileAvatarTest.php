<?php

namespace Tests\Unit\Models;

use App\Models\AiImage;
use App\Models\AiVideo;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileAvatarTest extends TestCase
{
    #[Test]
    public function profile_avatars_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('profile_avatars'));

        $this->assertTrue(Schema::hasColumns('profile_avatars', [
            'id',
            'user_id',
            'profile_id',
            'aiimage_id',
            'ai_video_id',
            'file',
            'status',
            'deleted_at',
            'created_at',
            'updated_at',
        ]));
    }

    #[Test]
    public function profile_avatar_belongs_to_related_models(): void
    {
        $user = User::factory()->create();
        $profile = $this->profile($user);
        $aiImage = AiImage::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source_id' => 'image-source-id',
            'source' => 'runway',
            'status' => 'succeeded',
            'file' => 'aiimages/1.png',
        ]);
        $aiVideo = AiVideo::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'source_id' => 'video-source-id',
            'source' => 'runway',
            'status' => 'succeeded',
            'file' => 'aivideos/1.mp4',
        ]);

        $avatar = ProfileAvatar::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'aiimage_id' => $aiImage->id,
            'ai_video_id' => $aiVideo->id,
            'file' => $aiVideo->file,
        ]);
        $avatar->refresh();

        $this->assertSame('active', $avatar->status);
        $this->assertTrue($avatar->user->is($user));
        $this->assertTrue($avatar->profile->is($profile));
        $this->assertTrue($avatar->aiImage->is($aiImage));
        $this->assertTrue($avatar->aiVideo->is($aiVideo));
        $this->assertTrue($user->profileAvatars()->first()->is($avatar));
        $this->assertTrue($profile->avatars()->first()->is($avatar));
    }

    private function profile(User $user): Profile
    {
        return Profile::create([
            'user_id' => $user->id,
            'name' => 'Test profile',
            'description' => 'Test description',
            'genre' => 'test',
            'personality' => 'friendly',
            'active' => true,
        ]);
    }
}
