<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\api\v1;

use App\Classes\Repositories\AvatarRepository;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\AiImage;
use App\Models\AiVideo;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\Subscription;
use App\Models\SubscriptionLimit;
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
        $this->createActiveSubscriptionFor($user);
        $token = $user->createToken('test-token', ['avatar:write'])->plainTextToken;
        $aiImage = $this->aiImageForProfile($profile);
        $processingAvatar = ProfileAvatar::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'aiimage_id' => $aiImage->id,
            'status' => ProfileAvatar::STATUS_PROCESSING,
        ]);

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

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post(self::ENDPOINT_GENERATE, [
                'profile_id' => $profile->id,
                'image' => $this->validImageUpload(),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Avatar generation started successfully.');
        $response->assertJsonPath('data.id', $aiImage->id);
        $response->assertJsonPath('data.profile_id', $profile->id);
        $response->assertJsonPath('data.avatar.id', $processingAvatar->id);
        $response->assertJsonPath('data.avatar.status', ProfileAvatar::STATUS_PROCESSING);
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

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
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
        $this->createActiveSubscriptionFor($owner);
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

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
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

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
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

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
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
            'status' => ProfileAvatar::STATUS_ACTIVE,
        ]);
        ProfileAvatar::create([
            'user_id' => $owner->id,
            'profile_id' => $profile->id,
            'status' => ProfileAvatar::STATUS_PROCESSING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/avatar/'.$profile->id);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Avatar retrieved successfully.');
        $response->assertJsonPath('data.id', $avatar->id);
        $response->assertJsonPath('data.ai_video_id', $aiVideo->id);
        $response->assertJsonPath('data.file', 'aivideos/1.mp4');
        $response->assertJsonPath('data.has_processing_avatar', true);
        $response->assertJsonPath('data.processing_avatar.status', ProfileAvatar::STATUS_PROCESSING);
    }

    public function test_user_can_list_own_avatar_history(): void
    {
        $user = User::factory()->create();
        $profile = $this->profileForUser($user);
        $token = $user->createToken('test-token', ['avatar:read'])->plainTextToken;
        $active = $this->avatarForProfile($profile, ProfileAvatar::STATUS_ACTIVE, 'aivideos/active.mp4');
        $inactive = $this->avatarForProfile($profile, ProfileAvatar::STATUS_INACTIVE, 'aivideos/old.mp4');
        $processing = ProfileAvatar::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => ProfileAvatar::STATUS_PROCESSING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get("/api/avatar/{$profile->id}/history");

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Avatar history retrieved successfully.');
        $response->assertJsonPath('data.total', 3);
        $response->assertJsonPath('data.processing_avatar.id', $processing->id);
        $response->assertJsonPath('data.active_avatar.id', $active->id);
        $response->assertJsonPath('data.avatars.0.id', $processing->id);
        $response->assertJsonPath('data.avatars.1.id', $active->id);
        $response->assertJsonPath('data.avatars.2.id', $inactive->id);
    }

    public function test_user_can_not_list_avatar_history_for_other_profile(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $profile = $this->profileForUser($otherUser);
        $token = $user->createToken('test-token', ['avatar:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get("/api/avatar/{$profile->id}/history");

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Profile not found.');
    }

    public function test_user_can_activate_inactive_avatar(): void
    {
        $user = User::factory()->create();
        $profile = $this->profileForUser($user);
        $token = $user->createToken('test-token', ['avatar:write'])->plainTextToken;
        $active = $this->avatarForProfile($profile, ProfileAvatar::STATUS_ACTIVE, 'aivideos/active.mp4');
        $inactive = $this->avatarForProfile($profile, ProfileAvatar::STATUS_INACTIVE, 'aivideos/old.mp4');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post("/api/avatar/{$profile->id}/activate", [
                'avatar_id' => $inactive->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('message', 'Avatar activated successfully.');
        $response->assertJsonPath('data.id', $inactive->id);
        $response->assertJsonPath('data.status', ProfileAvatar::STATUS_ACTIVE);

        $this->assertSame(ProfileAvatar::STATUS_INACTIVE, $active->fresh()->status);
        $this->assertSame(ProfileAvatar::STATUS_ACTIVE, $inactive->fresh()->status);
    }

    public function test_user_can_not_activate_avatar_while_generation_is_processing(): void
    {
        $user = User::factory()->create();
        $profile = $this->profileForUser($user);
        $token = $user->createToken('test-token', ['avatar:write'])->plainTextToken;
        $inactive = $this->avatarForProfile($profile, ProfileAvatar::STATUS_INACTIVE, 'aivideos/old.mp4');
        ProfileAvatar::create([
            'user_id' => $user->id,
            'profile_id' => $profile->id,
            'status' => ProfileAvatar::STATUS_PROCESSING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post("/api/avatar/{$profile->id}/activate", [
                'avatar_id' => $inactive->id,
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('message', 'Avatar generation is still processing for this profile.');
    }

    public function test_failed_avatar_can_not_be_activated(): void
    {
        $user = User::factory()->create();
        $profile = $this->profileForUser($user);
        $token = $user->createToken('test-token', ['avatar:write'])->plainTextToken;
        $failed = $this->avatarForProfile($profile, ProfileAvatar::STATUS_FAILED, null);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post("/api/avatar/{$profile->id}/activate", [
                'avatar_id' => $failed->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Avatar cannot be activated.');
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

    private function avatarForProfile(Profile $profile, string $status, ?string $file): ProfileAvatar
    {
        $aiImage = $this->aiImageForProfile($profile, 'succeeded', $file ? str_replace('aivideos/', 'aiimages/', $file).'.png' : null);
        $aiVideo = AiVideo::create([
            'user_id' => $profile->user_id,
            'profile_id' => $profile->id,
            'aiimage_id' => $aiImage->id,
            'source_id' => 'video-source-id-'.uniqid(),
            'source' => 'runway',
            'status' => $file ? 'succeeded' : 'failed',
            'file' => $file,
        ]);

        return ProfileAvatar::create([
            'user_id' => $profile->user_id,
            'profile_id' => $profile->id,
            'aiimage_id' => $aiImage->id,
            'ai_video_id' => $aiVideo->id,
            'file' => $file,
            'status' => $status,
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

    private function createActiveSubscriptionFor(User $user): Subscription
    {
        $subscription = Subscription::create([
            'user_id' => $user->id,
            'plan' => SubscriptionPlan::Starter,
            'started_at' => now()->subDay(),
            'renews_at' => now()->addMonth(),
            'status' => SubscriptionStatus::First,
            'active' => true,
        ]);

        SubscriptionLimit::create([
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'period_started_at' => $subscription->started_at,
            'period_renews_at' => $subscription->renews_at,
            'profiles_remaining' => 1,
            'avatar_images_remaining' => 1,
            'avatar_video_seconds_remaining' => 5,
            'voice_clones_remaining' => 1,
            'tts_characters_remaining' => 10000,
            'chat_messages_remaining' => 1000,
            'credits_remaining' => 1000,
        ]);

        return $subscription;
    }
}
