<?php

namespace App\Listeners\AI\Videos;

use App\Classes\Subscriptions\SubscriptionUsageRecorder;
use App\Classes\VideoAIService\VideoAIService;
use App\Events\AI\Images\AiImageForAvatarGenerated;
use App\Events\AI\Videos\AiVideoForAvatarCreated;
use App\Models\AiVideo as AiVideoModel;
use App\Models\ProfileAvatar;
use App\Models\User;
use App\Services\Notifications\NotificationDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CreateAiVideoForAvatar implements ShouldQueue
{
    use InteractsWithQueue;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(private readonly VideoAIService $videoAIService) {}

    public function handle(AiImageForAvatarGenerated $event): void
    {
        $aiImage = $event->aiImage->fresh();

        if (! $aiImage) {
            Log::warning('CreateAiVideoForAvatar skipped because AiImage no longer exists.');

            return;
        }

        try {
            $existingAiVideo = AiVideoModel::where('aiimage_id', $aiImage->id)
                ->orderByDesc('id')
                ->first();

            if ($existingAiVideo) {
                Log::info('CreateAiVideoForAvatar skipped because AiVideo already exists for AiImage.', [
                    'aiimage_id' => $aiImage->id,
                    'aivideo_id' => $existingAiVideo->id,
                    'source_id' => $existingAiVideo->source_id,
                    'status' => $existingAiVideo->status,
                ]);

                return;
            }

            Log::info('CreateAiVideoForAvatar listener triggered', [
                'aiimage_id' => $aiImage->id,
                'source_image_url' => $event->sourceImageUrl,
            ]);

            try {
                $aiVideo = AiVideoModel::create([
                    'user_id' => $aiImage->user_id,
                    'profile_id' => $aiImage->profile_id,
                    'aiimage_id' => $aiImage->id,
                    'source_id' => 'creating-'.Str::uuid()->toString(),
                    'source' => config('videoai.default', 'runway'),
                    'status' => 'creating',
                    'file' => null,
                ]);
            } catch (QueryException $e) {
                $existingAiVideo = AiVideoModel::where('aiimage_id', $aiImage->id)->first();

                if ($existingAiVideo) {
                    Log::info('CreateAiVideoForAvatar skipped because another job already created AiVideo.', [
                        'aiimage_id' => $aiImage->id,
                        'aivideo_id' => $existingAiVideo->id,
                        'source_id' => $existingAiVideo->source_id,
                        'status' => $existingAiVideo->status,
                    ]);

                    return;
                }

                throw $e;
            }

            $video = $this->videoAIService->createVideo(
                $event->sourceImageUrl,
                config('videoai.prompts.video')
            );

            if (! $video->id) {
                $failureReason = $this->failureReasonFromResponse(
                    $video->getResponse(),
                    'The video provider did not return a source id.'
                );
                $failureCode = $this->failureCodeFromResponse($video->getResponse());

                $aiVideo->status = 'failed';
                $aiVideo->failure_code = $failureCode;
                $aiVideo->failure_reason = $failureReason;
                $aiVideo->save();
                $avatar = $this->markAvatarFailed($aiImage->id, $failureReason, $failureCode);
                $this->releaseAvatarUsage($avatar);
                $this->notifyAvatarFailure($aiImage->id, $failureReason);
                throw new RuntimeException('Video AI video generation did not return a source id.');
            }

            $aiVideo->source_id = $video->id;
            $aiVideo->status = $this->normalizeStatus($video->status);
            $aiVideo->save();

            Log::info('AI video record created', [
                'aivideo_id' => $aiVideo->id,
                'aiimage_id' => $aiImage->id,
                'source_id' => $aiVideo->source_id,
            ]);

            event(new AiVideoForAvatarCreated($aiVideo, $aiImage));
        } catch (Throwable $e) {
            Log::error('CreateAiVideoForAvatar listener failed during processing', [
                'aiimage_id' => $aiImage->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    public function failed(AiImageForAvatarGenerated $event, Throwable $exception): void
    {
        $failureReason = $exception->getMessage();
        AiVideoModel::where('aiimage_id', $event->aiImage->id)
            ->whereIn('status', ['creating', 'pending', 'processing', 'running', 'queued'])
            ->latest('id')
            ->first()
            ?->update([
                'status' => 'failed',
                'failure_reason' => $failureReason,
            ]);

        $avatar = $this->markAvatarFailed($event->aiImage->id, $failureReason);
        $this->releaseAvatarUsage($avatar);
        $this->notifyAvatarFailure($event->aiImage->id, $failureReason);

        Log::error('CreateAiVideoForAvatar listener failed', [
            'aiimage_id' => $event->aiImage->id,
            'error' => $exception->getMessage(),
            'exception_class' => get_class($exception),
            'attempts' => $this->attempts(),
        ]);
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
