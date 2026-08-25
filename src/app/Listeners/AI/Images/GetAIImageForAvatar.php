<?php

namespace App\Listeners\AI\Images;

use App\Classes\Subscriptions\AvatarGenerationUsageService;
use App\Classes\VideoAIService\VideoAIArtifactStorage;
use App\Classes\VideoAIService\VideoAIService;
use App\Enums\AvatarGenerationStatus;
use App\Events\AI\Images\AiImageForAvatarCreated;
use App\Events\AI\Images\AiImageForAvatarGenerated;
use App\Listeners\Concerns\RoutesToMediaQueue;
use App\Models\ProfileAvatar;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class GetAIImageForAvatar implements ShouldQueue
{
    use InteractsWithQueue, RoutesToMediaQueue;

    public int $tries = 5;

    public int $backoff = 30;

    public int $timeout = 120;

    public function __construct(
        private readonly VideoAIService $videoAIService,
        private readonly VideoAIArtifactStorage $artifactStorage
    ) {}

    public function handle(AiImageForAvatarCreated $event): void
    {
        $aiImage = $event->aiImage->fresh();

        if (! $aiImage) {
            Log::warning('GetAIImageForAvatar skipped because AiImage no longer exists.');

            return;
        }

        if ($aiImage->status === 'succeeded' && $aiImage->file) {
            Log::info('GetAIImageForAvatar skipped because AiImage is already stored.', [
                'aiimage_id' => $aiImage->id,
                'source_id' => $aiImage->source_id,
                'file' => $aiImage->file,
            ]);

            return;
        }

        try {
            Log::info('GetAIImageForAvatar listener triggered', [
                'aiimage_id' => $aiImage->id,
                'source_id' => $aiImage->source_id,
                'attempt' => $this->attempts(),
            ]);

            $image = $this->videoAIService->getImage($aiImage->source_id);
            $status = $this->normalizeStatus($image->status);
            $aiImage->status = $status;

            if ($image->isSuccessful() && $image->getOutputUrl()) {
                $file = $this->artifactStorage->storeImageFromUrl($image->getOutputUrl(), $aiImage->id);

                $aiImage->status = 'succeeded';
                $aiImage->file = $file;
                $aiImage->failure_code = null;
                $aiImage->failure_reason = null;
                $aiImage->save();

                Log::info('AI image generated and stored', [
                    'aiimage_id' => $aiImage->id,
                    'file' => $file,
                ]);

                event(new AiImageForAvatarGenerated($aiImage->fresh(), $image->getOutputUrl()));

                return;
            }

            if ($image->isFailed()) {
                $response = $image->getResponse();
                $failureReason = $this->failureReasonFromResponse(
                    $response,
                    'The image provider returned a failed status.'
                );
                $failureCode = $this->failureCodeFromResponse($response);

                $aiImage->status = 'failed';
                $aiImage->failure_code = $failureCode;
                $aiImage->failure_reason = $failureReason;
                $aiImage->save();
                $avatar = $this->markAvatarFailed($aiImage->id, $failureReason, $failureCode);
                $this->releaseAvatarUsage($avatar);
                $this->notifyAvatarFailure($aiImage->id, $failureReason);

                Log::error('AI image generation failed at provider', [
                    'aiimage_id' => $aiImage->id,
                    'source_id' => $aiImage->source_id,
                    'response' => $response,
                ]);

                return;
            }

            $aiImage->save();
            $this->releaseOrMarkFailed($aiImage);
        } catch (Throwable $e) {
            Log::error('GetAIImageForAvatar listener failed during processing', [
                'aiimage_id' => $aiImage->id,
                'source_id' => $aiImage->source_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(AiImageForAvatarCreated $event, Throwable $exception): void
    {
        $aiImage = $event->aiImage->fresh();

        if ($aiImage) {
            $failureReason = $exception->getMessage();
            $aiImage->status = 'failed';
            $aiImage->failure_reason = $failureReason;
            $aiImage->save();
            $avatar = $this->markAvatarFailed($aiImage->id, $failureReason);
            $this->releaseAvatarUsage($avatar);
            $this->notifyAvatarFailure($aiImage->id, $failureReason);
        }

        Log::error('GetAIImageForAvatar listener failed', [
            'aiimage_id' => $event->aiImage->id,
            'source_id' => $event->aiImage->source_id,
            'error' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'attempts' => $this->attempts(),
        ]);
    }

    private function releaseOrMarkFailed($aiImage): void
    {
        if ($this->attempts() >= $this->tries) {
            $failureReason = 'Image generation timed out.';
            $aiImage->status = 'failed';
            $aiImage->failure_reason = $failureReason;
            $aiImage->save();
            $avatar = $this->markAvatarFailed($aiImage->id, $failureReason);
            $this->releaseAvatarUsage($avatar);
            $this->notifyAvatarFailure($aiImage->id, $failureReason);

            Log::error('AI image generation exceeded max attempts', [
                'aiimage_id' => $aiImage->id,
                'source_id' => $aiImage->source_id,
                'attempts' => $this->attempts(),
            ]);

            return;
        }

        Log::info('AI image not ready, releasing job', [
            'aiimage_id' => $aiImage->id,
            'source_id' => $aiImage->source_id,
            'delay' => $this->backoff,
            'attempt' => $this->attempts(),
        ]);

        if ($this->job) {
            $this->release($this->backoff);
        }
    }

    private function normalizeStatus(string $status): string
    {
        return strtolower($status);
    }

    private function markAvatarFailed(int|string $aiImageId, string $reason, ?string $code = null): ?ProfileAvatar
    {
        $avatar = ProfileAvatar::where('aiimage_id', $aiImageId)
            ->where('status', ProfileAvatar::STATUS_PROCESSING)
            ->latest('id')
            ->first();

        if (! $avatar) {
            return null;
        }

        $avatar->status = ProfileAvatar::STATUS_FAILED;
        $avatar->generation_status = AvatarGenerationStatus::ImageFailed;
        $avatar->failure_code = $code;
        $avatar->failure_reason = $reason;
        $avatar->save();

        return $avatar->fresh(['profile.user']);
    }

    private function notifyAvatarFailure(int|string $aiImageId, string $reason): void
    {
        $avatar = ProfileAvatar::query()
            ->with('profile.user')
            ->where('aiimage_id', $aiImageId)
            ->latest('id')
            ->first();

        if (! $avatar || ! $avatar->profile || ! $avatar->profile->user instanceof User) {
            return;
        }

        $profile = $avatar->profile;
        $data = [
            'profile' => $profile->name ?: "Profile {$profile->id}",
            'profile_id' => $profile->id,
            'reason' => $reason,
            'action_url' => "/dashboard/profiles/{$profile->id}/avatar",
        ];

        app(NotificationDispatcher::class)->send($profile->user, 'avatar_generation_failed', $data);
        app(NotificationDispatcher::class)->sendToAdmins('external_integration_error', [
            'service' => 'Video AI image provider',
            'message' => $reason,
        ]);
    }

    private function releaseAvatarUsage(?ProfileAvatar $avatar): void
    {
        if (! $avatar) {
            return;
        }

        app(AvatarGenerationUsageService::class)->release($avatar);
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function failureReasonFromResponse(array $response, string $fallback): string
    {
        foreach (['failure', 'error', 'message', 'detail'] as $key) {
            $value = $response[$key] ?? null;

            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }

            if (is_array($value)) {
                $encoded = json_encode($value);

                if (is_string($encoded) && $encoded !== '') {
                    return $encoded;
                }
            }
        }

        return $fallback;
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function failureCodeFromResponse(array $response): ?string
    {
        foreach (['failureCode', 'failure_code', 'errorCode', 'error_code', 'code'] as $key) {
            $value = $response[$key] ?? null;

            if (is_scalar($value) && (string) $value !== '') {
                return substr((string) $value, 0, 100);
            }
        }

        return null;
    }
}
