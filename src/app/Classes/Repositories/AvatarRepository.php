<?php

namespace App\Classes\Repositories;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Classes\VideoAIService\VideoAIService;
use App\Enums\SubscriptionUsageType;
use App\Events\AI\Images\AiImageForAvatarCreated;
use App\Exceptions\Avatar\AvatarGenerationInProgressException;
use App\Models\AiImage;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class AvatarRepository
{
    private ?VideoAIService $videoAIService = null;

    public function __construct(private readonly ?SubscriptionUsageRecorder $usageRecorder = null) {}

    public function setVideoAIService(VideoAIService $videoAIService): self
    {
        $this->videoAIService = $videoAIService;

        return $this;
    }

    public function generateAvatar(User $actor, Profile $profile, UploadedFile $sourceImage): AiImage
    {
        try {
            $profile->loadMissing('user');

            if ($this->getProcessingAvatarForProfile($profile)) {
                throw new AvatarGenerationInProgressException('Avatar generation is already processing for this profile.');
            }

            $disk = $this->profileArtifactDisk();
            $path = $sourceImage->store($this->sourceImageFolder(), $disk);

            if (! is_string($path)) {
                throw new RuntimeException('Avatar source image could not be stored.');
            }

            $sourceImageUri = $this->sourceImageToDataUri($path, $sourceImage, $disk);
            $owner = $profile->user ?: $actor;

            $processingAvatar = ProfileAvatar::create([
                'user_id' => $owner->id,
                'profile_id' => $profile->id,
                'aiimage_id' => null,
                'ai_video_id' => null,
                'file' => null,
                'status' => ProfileAvatar::STATUS_PROCESSING,
            ]);

            Log::info('Avatar image generation started.', [
                'actor_user_id' => $actor->id,
                'owner_user_id' => $owner->id,
                'profile_id' => $profile->id,
                'source_image_path' => $path,
            ]);

            try {
                $this->recordAvatarUsage($owner, $profile, $processingAvatar);
            } catch (Throwable $e) {
                $processingAvatar->status = ProfileAvatar::STATUS_FAILED;
                $processingAvatar->save();

                throw $e;
            }

            try {
                $aiImage = $this->videoAIService()->generateImage($owner, $sourceImageUri, $profile);
            } catch (Throwable $e) {
                $processingAvatar->status = ProfileAvatar::STATUS_FAILED;
                $processingAvatar->save();
                $this->releaseAvatarUsage($processingAvatar);

                throw $e;
            }

            $processingAvatar->aiimage_id = $aiImage->id;
            $processingAvatar->save();

            event(new AiImageForAvatarCreated($aiImage));

            Log::info('Avatar image generation queued.', [
                'actor_user_id' => $actor->id,
                'owner_user_id' => $owner->id,
                'profile_id' => $profile->id,
                'aiimage_id' => $aiImage->id,
                'source_id' => $aiImage->source_id,
            ]);

            return $aiImage;
        } catch (Throwable $e) {
            Log::error('Avatar image generation failed.', [
                'actor_user_id' => $actor->id,
                'profile_id' => $profile->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function getActiveAvatarForProfile(Profile $profile): ?ProfileAvatar
    {
        return $profile->avatars()
            ->with(['aiImage', 'aiVideo'])
            ->where('status', ProfileAvatar::STATUS_ACTIVE)
            ->orderByDesc('updated_at')
            ->first();
    }

    public function getProcessingAvatarForProfile(Profile $profile): ?ProfileAvatar
    {
        return $profile->avatars()
            ->with(['aiImage', 'aiVideo'])
            ->where('status', ProfileAvatar::STATUS_PROCESSING)
            ->orderByDesc('updated_at')
            ->first();
    }

    /**
     * @return Collection<int, ProfileAvatar>
     */
    public function getAvatarHistoryForProfile(Profile $profile): Collection
    {
        return $profile->avatars()
            ->with(['aiImage', 'aiVideo'])
            ->orderByRaw(
                'CASE status
                    WHEN ? THEN 0
                    WHEN ? THEN 1
                    WHEN ? THEN 2
                    ELSE 3
                END',
                [
                    ProfileAvatar::STATUS_PROCESSING,
                    ProfileAvatar::STATUS_ACTIVE,
                    ProfileAvatar::STATUS_INACTIVE,
                ]
            )
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
    }

    public function activateAvatar(Profile $profile, ProfileAvatar $avatar): ProfileAvatar
    {
        if ((int) $avatar->profile_id !== (int) $profile->id) {
            throw new RuntimeException('Avatar not found.');
        }

        if ($this->getProcessingAvatarForProfile($profile)) {
            throw new AvatarGenerationInProgressException('Avatar generation is still processing for this profile.');
        }

        if (! $avatar->isSelectable()) {
            throw new RuntimeException('Avatar cannot be activated.');
        }

        return DB::transaction(function () use ($profile, $avatar): ProfileAvatar {
            ProfileAvatar::where('profile_id', $profile->id)
                ->where('status', ProfileAvatar::STATUS_ACTIVE)
                ->where('id', '<>', $avatar->id)
                ->update(['status' => ProfileAvatar::STATUS_INACTIVE]);

            $avatar->status = ProfileAvatar::STATUS_ACTIVE;
            $avatar->save();

            return $avatar->fresh(['aiImage', 'aiVideo']);
        });
    }

    private function videoAIService(): VideoAIService
    {
        if (! $this->videoAIService) {
            $this->videoAIService = app(VideoAIService::class);
        }

        return $this->videoAIService;
    }

    private function recordAvatarUsage(User $owner, Profile $profile, ProfileAvatar $avatar): void
    {
        DB::transaction(function () use ($avatar, $owner, $profile): void {
            $this->usageRecorder()->record(
                userId: $owner->id,
                usageType: SubscriptionUsageType::AvatarImageCreated,
                amounts: ['avatar_images' => 1],
                idempotencyKey: $this->avatarImageUsageKey($avatar),
                profileId: $profile->id,
                sourceType: ProfileAvatar::class,
                sourceId: (string) $avatar->id,
                metadata: [
                    'reservation' => 'avatar_generation',
                ],
            );

            $videoSeconds = $this->avatarVideoSeconds();

            $this->usageRecorder()->record(
                userId: $owner->id,
                usageType: SubscriptionUsageType::AvatarVideoCreated,
                amounts: ['avatar_video_seconds' => $videoSeconds],
                idempotencyKey: $this->avatarVideoUsageKey($avatar),
                profileId: $profile->id,
                sourceType: ProfileAvatar::class,
                sourceId: (string) $avatar->id,
                metadata: [
                    'duration_seconds' => $videoSeconds,
                    'reservation' => 'avatar_generation',
                ],
            );
        });
    }

    private function releaseAvatarUsage(ProfileAvatar $avatar): void
    {
        $this->usageRecorder()->release($this->avatarImageUsageKey($avatar));
        $this->usageRecorder()->release($this->avatarVideoUsageKey($avatar));
    }

    private function avatarImageUsageKey(ProfileAvatar $avatar): string
    {
        return "avatar-image:profile-avatar:{$avatar->id}";
    }

    private function avatarVideoUsageKey(ProfileAvatar $avatar): string
    {
        return "avatar-video:profile-avatar:{$avatar->id}";
    }

    private function usageRecorder(): SubscriptionUsageRecorder
    {
        return $this->usageRecorder ?? app(SubscriptionUsageRecorder::class);
    }

    private function avatarVideoSeconds(): int
    {
        $driver = config('videoai.default', 'runway');

        return max(1, (int) config("videoai.drivers.{$driver}.default_duration", 5));
    }

    private function sourceImageToDataUri(string $path, UploadedFile $sourceImage, string $disk): string
    {
        $content = Storage::disk($disk)->get($path);
        $mimeType = $sourceImage->getMimeType() ?: 'image/png';

        return "data:{$mimeType};base64,".base64_encode($content);
    }

    private function profileArtifactDisk(): string
    {
        return (string) config('videoai.profiles.disk', 'profiles');
    }

    private function sourceImageFolder(): string
    {
        $folder = trim((string) config('videoai.profiles.source_image_folder', 'images/sources'), '/');

        return $folder !== '' ? $folder : 'images/sources';
    }
}
