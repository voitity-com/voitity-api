<?php

namespace App\Classes\Repositories;

use App\Classes\VideoAIService\VideoAIService;
use App\Events\AI\Images\AiImageForAvatarCreated;
use App\Models\AiImage;
use App\Models\Profile;
use App\Models\ProfileAvatar;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class AvatarRepository
{
    private ?VideoAIService $videoAIService = null;

    public function setVideoAIService(VideoAIService $videoAIService): self
    {
        $this->videoAIService = $videoAIService;

        return $this;
    }

    public function generateAvatar(User $actor, Profile $profile, UploadedFile $sourceImage): AiImage
    {
        try {
            $profile->loadMissing('user');

            $path = $sourceImage->store('avatar-sources', 'public');

            if (!is_string($path)) {
                throw new RuntimeException('Avatar source image could not be stored.');
            }

            $sourceImageUrl = Storage::disk('public')->url($path);
            $owner = $profile->user ?: $actor;

            Log::info('Avatar image generation started.', [
                'actor_user_id' => $actor->id,
                'owner_user_id' => $owner->id,
                'profile_id' => $profile->id,
                'source_image_path' => $path,
            ]);

            $aiImage = $this->videoAIService()->generateImage($owner, $sourceImageUrl, $profile);

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
            ->where('status', 'active')
            ->orderByDesc('updated_at')
            ->first();
    }

    private function videoAIService(): VideoAIService
    {
        if (!$this->videoAIService) {
            $this->videoAIService = app(VideoAIService::class);
        }

        return $this->videoAIService;
    }
}
