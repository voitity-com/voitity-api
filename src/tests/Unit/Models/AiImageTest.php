<?php

namespace Tests\Unit\Models;

use App\Models\AiImage;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiImageTest extends TestCase
{
    #[Test]
    public function aiimages_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('aiimages'));

        $this->assertTrue(Schema::hasColumns('aiimages', [
            'id',
            'user_id',
            'profile_id',
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
    public function ai_image_belongs_to_user_and_optional_profile(): void
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
            'source_id' => 'runway-task-id',
            'source' => 'runway',
            'status' => 'succeeded',
            'file' => 'images/runway-task-id.png',
        ]);

        $this->assertSame('succeeded', $aiImage->status);
        $this->assertTrue($aiImage->user->is($user));
        $this->assertTrue($aiImage->profile->is($profile));
        $this->assertTrue($user->aiImages()->first()->is($aiImage));
        $this->assertTrue($profile->aiImages()->first()->is($aiImage));
    }

    #[Test]
    public function ai_image_can_be_created_without_profile(): void
    {
        $user = User::factory()->create();

        $aiImage = AiImage::create([
            'user_id' => $user->id,
            'profile_id' => null,
            'source_id' => 'runway-task-id',
            'source' => 'runway',
            'file' => 'images/runway-task-id.png',
        ]);
        $aiImage->refresh();

        $this->assertSame('pending', $aiImage->status);
        $this->assertNull($aiImage->profile_id);
        $this->assertNull($aiImage->profile);
    }
}
