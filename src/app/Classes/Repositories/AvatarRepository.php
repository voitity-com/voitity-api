<?php

namespace App\Classes\Repositories;

use App\Classes\AvatarImageValidation\AvatarImageValidator;
use App\Classes\Subscriptions\AvatarGenerationSpecification;
use App\Classes\Subscriptions\AvatarGenerationUsageService;
use App\Classes\VideoAIService\VideoAIService;
use App\Enums\AvatarGenerationStatus;
use App\Enums\AvatarVariant;
use App\Events\AI\Images\AiImageForAvatarCreated;
use App\Exceptions\Avatar\AvatarGenerationInProgressException;
use App\Exceptions\Avatar\AvatarImageValidationUnavailableException;
use App\Exceptions\Avatar\InvalidAvatarSourceImageException;
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

    public function __construct(
        private readonly ?AvatarGenerationUsageService $avatarUsage = null,
        private readonly ?AvatarGenerationSpecification $avatarSpecification = null,
        private readonly ?AvatarImageValidator $avatarImageValidator = null,
    ) {}

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

            $owner = $profile->user ?: $actor;
            $validation = $this->avatarImageValidator()->validate($sourceImage, (string) ($owner->locale ?: 'es'));

            Log::info('Avatar source image accepted for generation.', [
                'actor_user_id' => $actor->id,
                'owner_user_id' => $owner->id,
                'profile_id' => $profile->id,
                'validation_request_id' => $validation->requestId,
                'validation_summary' => $validation->summary,
            ]);

            $disk = $this->profileArtifactDisk();
            $path = $sourceImage->store($this->sourceImageFolder(), $disk);

            if (! is_string($path)) {
                throw new RuntimeException('Avatar source image could not be stored.');
            }

            $originalFile = Storage::disk($disk)->url($path);
            $sourceImageUri = $this->sourceImageToDataUri($path, $sourceImage, $disk);
            $processingAvatar = ProfileAvatar::create([
                'user_id' => $owner->id,
                'profile_id' => $profile->id,
                'aiimage_id' => null,
                'ai_video_id' => null,
                'video_duration_seconds' => $this->avatarSpecification()->videoDurationSeconds(),
                'original_file' => $originalFile,
                'file' => null,
                'status' => ProfileAvatar::STATUS_PROCESSING,
                'generation_status' => AvatarGenerationStatus::Processing,
                'selected_variant' => null,
            ]);

            Log::info('Avatar image generation started.', [
                'actor_user_id' => $actor->id,
                'owner_user_id' => $owner->id,
                'profile_id' => $profile->id,
                'profile_avatar_id' => $processingAvatar->id,
                'source_image_path' => $path,
            ]);

            try {
                $this->avatarUsage()->reserve($owner, $profile, $processingAvatar);
            } catch (Throwable $e) {
                $processingAvatar->status = ProfileAvatar::STATUS_FAILED;
                $processingAvatar->generation_status = AvatarGenerationStatus::ImageFailed;
                $processingAvatar->failure_reason = $e->getMessage();
                $processingAvatar->save();

                throw $e;
            }

            try {
                $aiImage = $this->videoAIService()->generateImage($owner, $sourceImageUri, $profile);
            } catch (Throwable $e) {
                $processingAvatar->status = ProfileAvatar::STATUS_FAILED;
                $processingAvatar->generation_status = AvatarGenerationStatus::ImageFailed;
                $processingAvatar->failure_reason = $e->getMessage();
                $processingAvatar->save();
                $this->avatarUsage()->release($processingAvatar);

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
        } catch (InvalidAvatarSourceImageException $e) {
            Log::warning('Avatar generation rejected by source image validation.', [
                'actor_user_id' => $actor->id,
                'owner_user_id' => $profile->user?->id ?: $profile->user_id,
                'profile_id' => $profile->id,
                'reason_codes' => $e->validationResult()->reasonCodes,
                'validation_request_id' => $e->validationResult()->requestId,
            ]);

            throw $e;
        } catch (AvatarImageValidationUnavailableException $e) {
            Log::error('Avatar generation stopped because source image validation is unavailable.', [
                'actor_user_id' => $actor->id,
                'owner_user_id' => $profile->user?->id ?: $profile->user_id,
                'profile_id' => $profile->id,
            ]);

            throw $e;
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

    public function activateAvatar(Profile $profile, ProfileAvatar $avatar, AvatarVariant $variant): ProfileAvatar
    {
        if ((int) $avatar->profile_id !== (int) $profile->id) {
            throw new RuntimeException('Avatar not found.');
        }

        if ($this->getProcessingAvatarForProfile($profile)) {
            throw new AvatarGenerationInProgressException('Avatar generation is still processing for this profile.');
        }

        if (! $avatar->isSelectable($variant)) {
            throw new RuntimeException('The selected avatar version is not available.');
        }

        return DB::transaction(function () use ($profile, $avatar, $variant): ProfileAvatar {
            ProfileAvatar::where('profile_id', $profile->id)
                ->where('status', ProfileAvatar::STATUS_ACTIVE)
                ->where('id', '<>', $avatar->id)
                ->update(['status' => ProfileAvatar::STATUS_INACTIVE]);

            $avatar->file = $avatar->variantFile($variant);
            $avatar->selected_variant = $variant;
            $avatar->status = ProfileAvatar::STATUS_ACTIVE;
            $avatar->save();

            Log::info('Avatar version activated.', [
                'profile_id' => $profile->id,
                'profile_avatar_id' => $avatar->id,
                'variant' => $variant->value,
            ]);

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

    private function avatarUsage(): AvatarGenerationUsageService
    {
        return $this->avatarUsage ?? app(AvatarGenerationUsageService::class);
    }

    private function avatarSpecification(): AvatarGenerationSpecification
    {
        return $this->avatarSpecification ?? app(AvatarGenerationSpecification::class);
    }

    private function avatarImageValidator(): AvatarImageValidator
    {
        return $this->avatarImageValidator ?? app(AvatarImageValidator::class);
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
