<?php

namespace App\Listeners\AI\Videos;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Classes\VideoAIService\VideoAIArtifactStorage;
use App\Classes\VideoAIService\VideoAIService;
use App\Events\AI\Videos\AiVideoForAvatarCreated;
use App\Models\ProfileAvatar;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class GetAIVideoForAvatar implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 20;

    public int $backoff = 30;

    public int $timeout = 120;

    public function __construct(
        private readonly VideoAIService $videoAIService,
        private readonly VideoAIArtifactStorage $artifactStorage
    ) {}

    public function handle(AiVideoForAvatarCreated $event): void
    {
        $aiVideo = $event->aiVideo->fresh();

        if (! $aiVideo) {
            Log::warning('GetAIVideoForAvatar skipped because AiVideo no longer exists.');

            return;
        }

        if ($aiVideo->status === 'succeeded' && $aiVideo->file) {
            $this->updateProfileAvatar($event, $aiVideo);

            Log::info('GetAIVideoForAvatar skipped because AiVideo is already stored.', [
                'aivideo_id' => $aiVideo->id,
                'source_id' => $aiVideo->source_id,
                'file' => $aiVideo->file,
            ]);

            return;
        }

        try {
            Log::info('GetAIVideoForAvatar listener triggered', [
                'aivideo_id' => $aiVideo->id,
                'source_id' => $aiVideo->source_id,
                'attempt' => $this->attempts(),
            ]);

            $video = $this->videoAIService->getVideo($aiVideo->source_id);
            $status = $this->normalizeStatus($video->status);
            $aiVideo->status = $status;

            if ($video->isSuccessful() && $video->getOutputUrl()) {
                $file = $this->artifactStorage->storeVideoFromUrl($video->getOutputUrl(), $aiVideo->id);

                $aiVideo->status = 'succeeded';
                $aiVideo->file = $file;
                $aiVideo->failure_code = null;
                $aiVideo->failure_reason = null;
                $aiVideo->save();

                $avatar = $this->updateProfileAvatar($event, $aiVideo);
                $this->notifyAvatarGenerated($avatar);

                Log::info('AI video generated and stored', [
                    'aivideo_id' => $aiVideo->id,
                    'file' => $file,
                ]);

                return;
            }

            if ($video->isFailed()) {
                $response = $video->getResponse();
                $failureReason = $this->failureReasonFromResponse(
                    $response,
                    'The video provider returned a failed status.'
                );
                $failureCode = $this->failureCodeFromResponse($response);

                $aiVideo->status = 'failed';
                $aiVideo->failure_code = $failureCode;
                $aiVideo->failure_reason = $failureReason;
                $aiVideo->save();
                $avatar = $this->markAvatarFailed($event, $failureReason, $failureCode);
                $this->releaseAvatarUsage($avatar);
                $this->notifyAvatarFailure($event, $failureReason);

                Log::error('AI video generation failed at provider', [
                    'aivideo_id' => $aiVideo->id,
                    'source_id' => $aiVideo->source_id,
                    'response' => $response,
                ]);

                return;
            }

            $aiVideo->save();
            $this->releaseOrMarkFailed($aiVideo);
        } catch (Throwable $e) {
            Log::error('GetAIVideoForAvatar listener failed during processing', [
                'aivideo_id' => $aiVideo->id,
                'source_id' => $aiVideo->source_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(AiVideoForAvatarCreated $event, Throwable $exception): void
    {
        $aiVideo = $event->aiVideo->fresh();

        if ($aiVideo) {
            $failureReason = $exception->getMessage();
            $aiVideo->status = 'failed';
            $aiVideo->failure_reason = $failureReason;
            $aiVideo->save();
            $avatar = $this->markAvatarFailed($event, $failureReason);
            $this->releaseAvatarUsage($avatar);
            $this->notifyAvatarFailure($event, $failureReason);
        }

        Log::error('GetAIVideoForAvatar listener failed', [
            'aivideo_id' => $event->aiVideo->id,
            'source_id' => $event->aiVideo->source_id,
            'error' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'attempts' => $this->attempts(),
        ]);
    }

    private function updateProfileAvatar(AiVideoForAvatarCreated $event, $aiVideo): ?ProfileAvatar
    {
        if (! $aiVideo->profile_id) {
            return null;
        }

        return DB::transaction(function () use ($event, $aiVideo): ProfileAvatar {
            $avatar = ProfileAvatar::where('profile_id', $aiVideo->profile_id)
                ->where('aiimage_id', $event->aiImage?->id)
                ->first();

            if (! $avatar) {
                $avatar = new ProfileAvatar([
                    'user_id' => $aiVideo->user_id,
                    'profile_id' => $aiVideo->profile_id,
                    'aiimage_id' => $event->aiImage?->id,
                ]);
            }

            ProfileAvatar::where('profile_id', $aiVideo->profile_id)
                ->where('status', ProfileAvatar::STATUS_ACTIVE)
                ->when($avatar->exists, fn ($query) => $query->where('id', '<>', $avatar->id))
                ->update(['status' => ProfileAvatar::STATUS_INACTIVE]);

            $avatar->user_id = $aiVideo->user_id;
            $avatar->profile_id = $aiVideo->profile_id;
            $avatar->aiimage_id = $event->aiImage?->id;
            $avatar->ai_video_id = $aiVideo->id;
            $avatar->file = $aiVideo->file;
            $avatar->status = ProfileAvatar::STATUS_ACTIVE;
            $avatar->failure_code = null;
            $avatar->failure_reason = null;
            $avatar->save();

            return $avatar->fresh(['profile.user']);
        });
    }

    private function releaseOrMarkFailed($aiVideo): void
    {
        if ($this->attempts() >= $this->tries) {
            $failureReason = 'Video generation timed out.';
            $aiVideo->status = 'failed';
            $aiVideo->failure_reason = $failureReason;
            $aiVideo->save();
            $avatar = $this->markAvatarFailedByVideo($aiVideo, $failureReason);
            $this->releaseAvatarUsage($avatar);
            $this->notifyAvatarFailureByVideo($aiVideo, $failureReason);

            Log::error('AI video generation exceeded max attempts', [
                'aivideo_id' => $aiVideo->id,
                'source_id' => $aiVideo->source_id,
                'attempts' => $this->attempts(),
            ]);

            return;
        }

        Log::info('AI video not ready, releasing job', [
            'aivideo_id' => $aiVideo->id,
            'source_id' => $aiVideo->source_id,
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

    private function markAvatarFailed(AiVideoForAvatarCreated $event, string $reason, ?string $code = null): ?ProfileAvatar
    {
        $aiImageId = $event->aiImage?->id ?? $event->aiVideo->aiimage_id;

        if (! $aiImageId) {
            return null;
        }

        return $this->markAvatarFailedForAiImage($aiImageId, $reason, $code);
    }

    private function markAvatarFailedByVideo($aiVideo, string $reason, ?string $code = null): ?ProfileAvatar
    {
        if (! $aiVideo->aiimage_id) {
            return null;
        }

        return $this->markAvatarFailedForAiImage($aiVideo->aiimage_id, $reason, $code);
    }

    private function markAvatarFailedForAiImage(int|string $aiImageId, string $reason, ?string $code = null): ?ProfileAvatar
    {
        $avatar = ProfileAvatar::where('aiimage_id', $aiImageId)
            ->where('status', ProfileAvatar::STATUS_PROCESSING)
            ->latest('id')
            ->first();

        if (! $avatar) {
            return null;
        }

        $avatar->status = ProfileAvatar::STATUS_FAILED;
        $avatar->failure_code = $code;
        $avatar->failure_reason = $reason;
        $avatar->save();

        return $avatar->fresh(['profile.user']);
    }

    private function notifyAvatarGenerated(?ProfileAvatar $avatar): void
    {
        if (! $avatar || ! $avatar->profile || ! $avatar->profile->user instanceof User) {
            return;
        }

        $profile = $avatar->profile;

        app(NotificationDispatcher::class)->sendInApp($profile->user, 'avatar_generated_successfully', [
            'profile' => $profile->name ?: "Profile {$profile->id}",
            'profile_id' => $profile->id,
            'action_url' => "/dashboard/profiles/{$profile->id}/avatar",
        ]);
    }

    private function notifyAvatarFailure(AiVideoForAvatarCreated $event, string $reason): void
    {
        $avatar = ProfileAvatar::query()
            ->with('profile.user')
            ->where('aiimage_id', $event->aiImage?->id ?? $event->aiVideo->aiimage_id)
            ->latest('id')
            ->first();

        $this->notifyAvatarFailureForAvatar($avatar, $reason);
    }

    private function notifyAvatarFailureByVideo($aiVideo, string $reason): void
    {
        $avatar = ProfileAvatar::query()
            ->with('profile.user')
            ->where('aiimage_id', $aiVideo->aiimage_id)
            ->latest('id')
            ->first();

        $this->notifyAvatarFailureForAvatar($avatar, $reason);
    }

    private function notifyAvatarFailureForAvatar(?ProfileAvatar $avatar, string $reason): void
    {
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
            'service' => 'Video AI video provider',
            'message' => $reason,
        ]);
    }

    private function releaseAvatarUsage(?ProfileAvatar $avatar): void
    {
        if (! $avatar) {
            return;
        }

        $recorder = app(SubscriptionUsageRecorder::class);
        $recorder->release("avatar-image:profile-avatar:{$avatar->id}");
        $recorder->release("avatar-video:profile-avatar:{$avatar->id}");
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
