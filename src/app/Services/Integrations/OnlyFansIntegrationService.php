<?php

namespace App\Services\Integrations;

use App\Classes\Subscriptions\SubscriptionPlanCapabilityService;
use App\Models\Profile;
use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use App\Models\User;
use App\Services\ProfileKnowledge\ProfileIntegrationKnowledgeLifecycle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OnlyFansIntegrationService
{
    public function __construct(
        private readonly SubscriptionPlanCapabilityService $capabilities,
        private readonly ProfileIntegrationKnowledgeLifecycle $knowledgeLifecycle,
    ) {}

    /**
     * @param  array{username: string, profile_url: string}  $attributes
     */
    public function connect(Profile $profile, User $user, array $attributes): ProfileIntegration
    {
        $username = ltrim(trim($attributes['username']), '@');
        $profileUrl = $this->normalizeProfileUrl($attributes['profile_url'], $username);
        $now = now();

        $integration = ProfileIntegration::query()->updateOrCreate(
            [
                'profile_id' => $profile->id,
                'provider' => ProfileIntegration::PROVIDER_ONLYFANS,
            ],
            [
                'user_id' => $user->id,
                'provider_user_id' => mb_strtolower($username),
                'username' => $username,
                'status' => ProfileIntegration::STATUS_CONNECTED,
                'metadata' => [
                    'adult_content_confirmed_at' => $now->toIso8601String(),
                    'connection_type' => 'manual_upload',
                    'profile_url' => $profileUrl,
                    'rights_confirmed_at' => $now->toIso8601String(),
                ],
            ]
        );

        return $integration->fresh();
    }

    public function upload(
        ProfileIntegration $integration,
        UploadedFile $file,
        ?string $caption,
        ?string $observation,
        bool $selected
    ): ProfileIntegrationMedia {
        $mimeType = strtolower((string) $file->getMimeType());
        $mediaType = str_starts_with($mimeType, 'video/') ? 'VIDEO' : 'IMAGE';
        $this->assertSupportedFile($file, $mediaType);
        $this->assertSelectionAvailable($integration, $selected);

        $uuid = (string) Str::uuid();
        $disk = (string) config('onlyfans.disk', 'profiles');
        $folder = trim((string) config('onlyfans.folder', 'integrations/onlyfans'), '/');
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $path = "{$folder}/{$integration->profile_id}/{$uuid}/media.{$extension}";
        $visibility = (string) config('onlyfans.visibility', 'public');

        Storage::disk($disk)->putFileAs(
            dirname($path),
            $file,
            basename($path),
            ['visibility' => $visibility]
        );

        try {
            return ProfileIntegrationMedia::query()->create([
                'profile_integration_id' => $integration->id,
                'profile_id' => $integration->profile_id,
                'provider' => ProfileIntegration::PROVIDER_ONLYFANS,
                'provider_media_id' => $uuid,
                'media_type' => $mediaType,
                'media_url' => Storage::disk($disk)->url($path),
                'storage_disk' => $disk,
                'storage_path' => $path,
                'permalink' => $integration->metadata['profile_url'] ?? null,
                'caption' => $this->nullableTrim($caption),
                'observation' => $this->nullableTrim($observation) ?: $this->nullableTrim($caption),
                'age_restricted' => true,
                'selected' => $selected,
                'taken_at' => now(),
                'metadata' => [
                    'mime_type' => $mimeType,
                    'original_filename' => $file->getClientOriginalName(),
                    'size_bytes' => $file->getSize(),
                    'source_type' => 'manual_upload',
                ],
            ]);
        } catch (\Throwable $e) {
            Storage::disk($disk)->delete($path);

            throw $e;
        }
    }

    /**
     * @param  array<int, array{id: int|string, selected?: bool, observation?: null|string}>  $items
     */
    public function updateSelection(ProfileIntegration $integration, array $items): ProfileIntegration
    {
        $itemsById = collect($items)->keyBy(fn (array $item): int => (int) $item['id']);
        $media = $integration->media()->get();
        $selectedCount = $media
            ->filter(function (ProfileIntegrationMedia $media) use ($itemsById): bool {
                $item = $itemsById->get((int) $media->id);

                return is_array($item)
                    ? (bool) ($item['selected'] ?? false)
                    : (bool) $media->selected;
            })
            ->count();
        $selectionLimit = $this->capabilities->selectedMediaPerProfile(
            $integration->profile,
            ProfileIntegration::PROVIDER_ONLYFANS
        );

        if ($selectedCount > $selectionLimit) {
            throw new InvalidArgumentException("You can select up to {$selectionLimit} OnlyFans items.");
        }

        DB::transaction(function () use ($media, $itemsById): void {
            $media->each(function (ProfileIntegrationMedia $media) use ($itemsById): void {
                $item = $itemsById->get((int) $media->id);

                if (! is_array($item)) {
                    return;
                }

                $observation = array_key_exists('observation', $item)
                    ? $this->nullableTrim((string) $item['observation'])
                    : $media->observation;

                $media->forceFill([
                    'selected' => (bool) ($item['selected'] ?? false),
                    'observation' => $observation ?: $media->caption,
                ])->save();
            });
        });

        $this->knowledgeLifecycle->selectionChanged($integration);

        return $integration->fresh(['media']);
    }

    public function deleteMedia(ProfileIntegration $integration, ProfileIntegrationMedia $media): void
    {
        if (
            (int) $media->profile_integration_id !== (int) $integration->id
            || $media->provider !== ProfileIntegration::PROVIDER_ONLYFANS
        ) {
            throw new InvalidArgumentException('OnlyFans media was not found.');
        }

        DB::transaction(function () use ($media): void {
            $disk = $media->storage_disk;
            $path = $media->storage_path;

            $media->deleteQuietly();

            if (filled($disk) && filled($path)) {
                Storage::disk($disk)->delete($path);
                Storage::disk($disk)->deleteDirectory(dirname($path));
            }
        });

        $this->knowledgeLifecycle->forgetMedia(
            (int) $integration->profile_id,
            [$media->id],
            ProfileIntegration::PROVIDER_ONLYFANS,
        );
    }

    public function disconnect(ProfileIntegration $integration): void
    {
        $media = $integration->media()->get(['id', 'storage_disk', 'storage_path']);

        DB::transaction(function () use ($integration): void {
            $integration->deleteQuietly();
        });

        $this->knowledgeLifecycle->forgetMedia(
            (int) $integration->profile_id,
            $media->pluck('id'),
            ProfileIntegration::PROVIDER_ONLYFANS,
        );

        foreach ($media as $item) {
            if (filled($item->storage_disk) && filled($item->storage_path)) {
                Storage::disk($item->storage_disk)->delete($item->storage_path);
                Storage::disk($item->storage_disk)->deleteDirectory(dirname($item->storage_path));
            }
        }
    }

    private function normalizeProfileUrl(string $value, string $username): string
    {
        $value = trim($value);
        $host = strtolower((string) parse_url($value, PHP_URL_HOST));
        $path = trim((string) parse_url($value, PHP_URL_PATH), '/');

        if (! in_array($host, ['onlyfans.com', 'www.onlyfans.com'], true) || $path === '') {
            throw new InvalidArgumentException('The profile URL must be a valid OnlyFans profile URL.');
        }

        $profileUsername = rawurldecode(explode('/', $path)[0]);

        if (mb_strtolower(ltrim($profileUsername, '@')) !== mb_strtolower($username)) {
            throw new InvalidArgumentException('The OnlyFans username must match the profile URL.');
        }

        return rtrim((string) config('onlyfans.profile_base_url', 'https://onlyfans.com'), '/').'/'.rawurlencode($username);
    }

    private function assertSupportedFile(UploadedFile $file, string $mediaType): void
    {
        $mimeType = strtolower((string) $file->getMimeType());
        $supported = [
            'image/gif',
            'image/jpeg',
            'image/png',
            'image/webp',
            'video/mp4',
            'video/quicktime',
            'video/webm',
        ];

        if (! in_array($mimeType, $supported, true)) {
            throw new InvalidArgumentException('Only JPG, PNG, WEBP, GIF, MP4, MOV, or WEBM files are supported.');
        }

        $limitMb = $mediaType === 'VIDEO'
            ? max(1, (int) config('onlyfans.max_video_size_mb', 100))
            : max(1, (int) config('onlyfans.max_image_size_mb', 10));

        if ((int) $file->getSize() > $limitMb * 1024 * 1024) {
            throw new InvalidArgumentException("The uploaded {$mediaType} may not be greater than {$limitMb} MB.");
        }
    }

    private function assertSelectionAvailable(ProfileIntegration $integration, bool $selected): void
    {
        if (! $selected) {
            return;
        }

        $selectionLimit = $this->capabilities->selectedMediaPerProfile(
            $integration->profile,
            ProfileIntegration::PROVIDER_ONLYFANS
        );

        if ($integration->media()->where('selected', true)->count() >= $selectionLimit) {
            throw new InvalidArgumentException("You can select up to {$selectionLimit} OnlyFans items.");
        }
    }

    private function nullableTrim(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
