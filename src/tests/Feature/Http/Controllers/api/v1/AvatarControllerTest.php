<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Classes\Repositories\AvatarRepository;
use App\Models\AiImage;
use App\Models\AiVideo;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Mockery;

class AvatarControllerTest extends TestAPI
{
    private const ENDPOINT_GENERATE = '/api/avatar/generate';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_user_can_generate_avatar_for_own_profile(): void
    {
        $user = User::factory()->create();
        $profile = $this->profileForUser($user);
        $token = $user->createToken('test-token', ['avatar:write'])->plainTextToken;
        $aiImage = $this->aiImageForProfile($profile);

        $this->mock(AvatarRepository::class, function ($mock) use ($user, $profile, $aiImage): void {
            $mock->shouldReceive('generateAvatar')
                ->once()
                ->with(
                    Mockery::on(fn (User $receivedUser) => $receivedUser->is($user)),
                    Mockery::on(fn (Profile $receivedProfile) => $receivedProfile->is($profile)),
                    Mockery::type(UploadedFile::class)
                )
                ->andReturn($aiImage);
        });

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post(self::ENDPOINT_GENERATE, [
                'profile_id' => $profile->id,
                'image' => $this->validImageUpload(),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Avatar generation started successfully.');
        $response->assertJsonPath('data.id', $aiImage->id);
        $response->assertJsonPath('data.profile_id', $profile->id);
    }

    public function test_user_can_not_generate_avatar_for_other_user_profile(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $profile = $this->profileForUser($otherUser);
        $token = $user->createToken('test-token', ['avatar:write'])->plainTextToken;

        $this->mock(AvatarRepository::class, function ($mock): void {
            $mock->shouldNotReceive('generateAvatar');
        });

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post(self::ENDPOINT_GENERATE, [
                'profile_id' => $profile->id,
                'image' => $this->validImageUpload(),
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Profile not found.');
    }

    public function test_admin_can_generate_avatar_for_any_profile(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $owner = User::factory()->create(['role' => 'user']);
        $profile = $this->profileForUser($owner);
        $token = $admin->createToken('test-token', ['avatar:write'])->plainTextToken;
        $aiImage = $this->aiImageForProfile($profile);

        $this->mock(AvatarRepository::class, function ($mock) use ($admin, $profile, $aiImage): void {
            $mock->shouldReceive('generateAvatar')
                ->once()
                ->with(
                    Mockery::on(fn (User $receivedUser) => $receivedUser->is($admin)),
                    Mockery::on(fn (Profile $receivedProfile) => $receivedProfile->is($profile)),
                    Mockery::type(UploadedFile::class)
                )
                ->andReturn($aiImage);
        });

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post(self::ENDPOINT_GENERATE, [
                'profile_id' => $profile->id,
                'image' => $this->validImageUpload(),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.profile_id', $profile->id);
    }

    public function test_generate_avatar_requires_valid_image(): void
    {
        $user = User::factory()->create();
        $profile = $this->profileForUser($user);
        $token = $user->createToken('test-token', ['avatar:write'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post(self::ENDPOINT_GENERATE, [
                'profile_id' => $profile->id,
                'image' => UploadedFile::fake()->create('avatar.txt', 1, 'text/plain'),
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['image']);
    }

    public function test_user_without_avatar_write_ability_can_not_generate_avatar(): void
    {
        $user = User::factory()->create();
        $profile = $this->profileForUser($user);
        $token = $user->createToken('test-token', ['avatar:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->post(self::ENDPOINT_GENERATE, [
                'profile_id' => $profile->id,
                'image' => $this->validImageUpload(),
            ]);

        $response->assertStatus(403);
    }

    public function test_user_with_avatar_read_ability_can_show_active_avatar_for_any_profile(): void
    {
        $viewer = User::factory()->create();
        $owner = User::factory()->create();
        $profile = $this->profileForUser($owner);
        $token = $viewer->createToken('test-token', ['avatar:read'])->plainTextToken;
        $aiImage = $this->aiImageForProfile($profile, 'succeeded', 'aiimages/1.png');
        $aiVideo = AiVideo::create([
            'user_id' => $owner->id,
            'profile_id' => $profile->id,
            'source_id' => 'video-source-id',
            'source' => 'runway',
            'status' => 'succeeded',
            'file' => 'aivideos/1.mp4',
        ]);
        $avatar = ProfileAvatar::create([
            'user_id' => $owner->id,
            'profile_id' => $profile->id,
            'aiimage_id' => $aiImage->id,
            'ai_video_id' => $aiVideo->id,
            'file' => $aiVideo->file,
            'status' => 'active',
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->get('/api/avatar/' . $profile->id);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Avatar retrieved successfully.');
        $response->assertJsonPath('data.id', $avatar->id);
        $response->assertJsonPath('data.ai_video_id', $aiVideo->id);
        $response->assertJsonPath('data.file', 'aivideos/1.mp4');
    }

    private function profileForUser(User $user): Profile
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

    private function aiImageForProfile(Profile $profile, string $status = 'pending', ?string $file = null): AiImage
    {
        return AiImage::create([
            'user_id' => $profile->user_id,
            'profile_id' => $profile->id,
            'source_id' => 'image-source-id',
            'source' => 'runway',
            'status' => $status,
            'file' => $file,
        ]);
    }

    private function validImageUpload(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'avatar_');
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='
        ));

        return new UploadedFile($path, 'avatar.png', 'image/png', null, true);
    }
}
