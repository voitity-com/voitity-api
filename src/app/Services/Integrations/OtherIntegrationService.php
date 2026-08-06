<?php

namespace App\Services\Integrations;

use App\Classes\Subscriptions\SubscriptionPlanCapabilityService;
use App\Enums\IntegrationDestinationType;
use App\Models\Profile;
use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OtherIntegrationService
{
    public function __construct(
        private readonly SubscriptionPlanCapabilityService $capabilities,
        private readonly IntegrationDestinationCatalog $destinations,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function upload(Profile $profile, User $actor, UploadedFile $file, array $attributes): ProfileIntegrationMedia
    {
        $mimeType = strtolower((string) $file->getMimeType());
        $mediaType = str_starts_with($mimeType, 'video/') ? 'VIDEO' : 'IMAGE';
        $this->assertSupportedFile($file, $mediaType);

        $uuid = (string) Str::uuid();
        $disk = (string) config('other.disk', 'profiles');
        $folder = trim((string) config('other.folder', 'integrations/other'), '/');
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $path = "{$folder}/{$profile->id}/{$uuid}/media.{$extension}";
        $visibility = (string) config('other.visibility', 'public');
        $stored = false;

        try {
            $media = DB::transaction(function () use (
                $profile,
                $actor,
                $file,
                $attributes,
                $uuid,
                $disk,
                $path,
                $visibility,
                $mimeType,
                $mediaType,
                &$stored,
            ): ProfileIntegrationMedia {
                $integration = ProfileIntegration::query()->firstOrCreate(
                    [
                        'profile_id' => $profile->id,
                        'provider' => ProfileIntegration::PROVIDER_OTHER,
                    ],
                    [
                        'user_id' => $profile->user_id,
                        'provider_user_id' => (string) $profile->id,
                        'username' => null,
                        'status' => ProfileIntegration::STATUS_CONNECTED,
                        'metadata' => [
                            'connection_type' => 'manual_upload',
                            'created_by_user_id' => $actor->id,
                        ],
                    ],
                );

                $selected = (bool) ($attributes['selected'] ?? false);
                $this->assertSelectionAvailable($integration, $selected);

                $stored = Storage::disk($disk)->putFileAs(
                    dirname($path),
                    $file,
                    basename($path),
                    ['visibility' => $visibility],
                );

                if (! $stored) {
                    throw new InvalidArgumentException('The media file could not be stored.');
                }

                $destination = IntegrationDestinationType::from((string) $attributes['destination_type']);
                $description = $this->trimmed((string) $attributes['description']);

                return ProfileIntegrationMedia::query()->create([
                    'profile_integration_id' => $integration->id,
                    'profile_id' => $profile->id,
                    'provider' => ProfileIntegration::PROVIDER_OTHER,
                    'provider_media_id' => $uuid,
                    'media_type' => $mediaType,
                    'media_url' => Storage::disk($disk)->url($path),
                    'storage_disk' => $disk,
                    'storage_path' => $path,
                    'permalink' => trim((string) $attributes['link']),
                    'caption' => $description,
                    'observation' => $description,
                    'age_restricted' => false,
                    'selected' => $selected,
                    'taken_at' => now(),
                    'metadata' => [
                        'action_type' => $this->destinations->actionType($destination)->value,
                        'custom_destination_label' => $this->customLabel($destination, $attributes),
                        'destination_type' => $destination->value,
                        'mime_type' => $mimeType,
                        'original_filename' => $file->getClientOriginalName(),
                        'size_bytes' => $file->getSize(),
                        'source_type' => 'manual_upload',
                    ],
                ]);
            });
        } catch (\Throwable $e) {
            if ($stored) {
                Storage::disk($disk)->delete($path);
                Storage::disk($disk)->deleteDirectory(dirname($path));
            }

            throw $e;
        }

        Log::info('Other integration media uploaded.', [
            'destination_type' => data_get($media->metadata, 'destination_type'),
            'media_id' => $media->id,
            'media_type' => $media->media_type,
            'profile_id' => $profile->id,
            'storage_disk' => $disk,
            'user_id' => $actor->id,
        ]);

        return $media;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(
        ProfileIntegration $integration,
        ProfileIntegrationMedia $media,
        array $attributes,
        User $actor,
    ): ProfileIntegrationMedia {
        $this->assertOwnedMedia($integration, $media);
        $destination = IntegrationDestinationType::from((string) $attributes['destination_type']);
        $description = $this->trimmed((string) $attributes['description']);
        $selected = (bool) ($attributes['selected'] ?? $media->selected);

        if ($selected && ! $media->selected) {
            $this->assertSelectionAvailable($integration, true);
        }

        $media->forceFill([
            'permalink' => trim((string) $attributes['link']),
            'caption' => $description,
            'observation' => $description,
            'selected' => $selected,
            'metadata' => [
                ...($media->metadata ?? []),
                'action_type' => $this->destinations->actionType($destination)->value,
                'custom_destination_label' => $this->customLabel($destination, $attributes),
                'destination_type' => $destination->value,
            ],
        ])->save();

        Log::info('Other integration media updated.', [
            'destination_type' => $destination->value,
            'media_id' => $media->id,
            'profile_id' => $integration->profile_id,
            'selected' => $selected,
            'user_id' => $actor->id,
        ]);

        return $media->fresh();
    }

    /**
     * @param  array<int, array{id: int|string, selected: bool}>  $items
     */
    public function updateSelection(ProfileIntegration $integration, array $items, User $actor): ProfileIntegration
    {
        $itemsById = collect($items)->keyBy(fn (array $item): int => (int) $item['id']);
        $media = $integration->media()->get();

        if ($itemsById->keys()->diff($media->pluck('id'))->isNotEmpty()) {
            throw new InvalidArgumentException('One or more media items were not found.');
        }

        $selectedCount = $media
            ->filter(function (ProfileIntegrationMedia $item) use ($itemsById): bool {
                $update = $itemsById->get((int) $item->id);

                return is_array($update) ? (bool) $update['selected'] : (bool) $item->selected;
            })
            ->count();
        $selectionLimit = $this->capabilities->selectedMediaPerProfile(
            $integration->profile,
            ProfileIntegration::PROVIDER_OTHER,
        );

        if ($selectedCount > $selectionLimit) {
            throw new InvalidArgumentException("You can select up to {$selectionLimit} Other items.");
        }

        DB::transaction(function () use ($media, $itemsById): void {
            $media->each(function (ProfileIntegrationMedia $item) use ($itemsById): void {
                $update = $itemsById->get((int) $item->id);

                if (is_array($update)) {
                    $item->forceFill(['selected' => (bool) $update['selected']])->save();
                }
            });
        });

        Log::info('Other integration media selection updated.', [
            'profile_id' => $integration->profile_id,
            'selected_count' => $selectedCount,
            'user_id' => $actor->id,
        ]);

        return $integration->fresh(['media']);
    }

    public function deleteMedia(
        ProfileIntegration $integration,
        ProfileIntegrationMedia $media,
        User $actor,
    ): void {
        $this->assertOwnedMedia($integration, $media);
        $mediaId = $media->id;
        $disk = $media->storage_disk;
        $path = $media->storage_path;

        DB::transaction(fn () => $media->delete());

        if (filled($disk) && filled($path)) {
            Storage::disk($disk)->delete($path);
            Storage::disk($disk)->deleteDirectory(dirname($path));
        }

        Log::info('Other integration media deleted.', [
            'media_id' => $mediaId,
            'profile_id' => $integration->profile_id,
            'storage_disk' => $disk,
            'user_id' => $actor->id,
        ]);
    }

    public function disconnect(ProfileIntegration $integration, User $actor): void
    {
        $media = $integration->media()->get(['id', 'storage_disk', 'storage_path']);
        $profileId = $integration->profile_id;

        DB::transaction(fn () => $integration->delete());

        foreach ($media as $item) {
            if (filled($item->storage_disk) && filled($item->storage_path)) {
                Storage::disk($item->storage_disk)->delete($item->storage_path);
                Storage::disk($item->storage_disk)->deleteDirectory(dirname($item->storage_path));
            }
        }

        Log::info('Other integration disconnected.', [
            'deleted_media_count' => $media->count(),
            'profile_id' => $profileId,
            'user_id' => $actor->id,
        ]);
    }

    private function assertSupportedFile(UploadedFile $file, string $mediaType): void
    {
        $supported = [
            'image/gif',
            'image/jpeg',
            'image/png',
            'image/webp',
            'video/mp4',
            'video/quicktime',
            'video/webm',
        ];
        $mimeType = strtolower((string) $file->getMimeType());

        if (! in_array($mimeType, $supported, true)) {
            throw new InvalidArgumentException('Only JPG, PNG, WEBP, GIF, MP4, MOV, or WEBM files are supported.');
        }

        $limitMb = $mediaType === 'VIDEO'
            ? max(1, (int) config('other.max_video_size_mb', 100))
            : max(1, (int) config('other.max_image_size_mb', 10));

        if ((int) $file->getSize() > $limitMb * 1024 * 1024) {
            throw new InvalidArgumentException("The uploaded {$mediaType} may not be greater than {$limitMb} MB.");
        }
    }

    private function assertSelectionAvailable(ProfileIntegration $integration, bool $selected): void
    {
        if (! $selected) {
            return;
        }

        $limit = $this->capabilities->selectedMediaPerProfile(
            $integration->profile,
            ProfileIntegration::PROVIDER_OTHER,
        );

        if ($integration->media()->where('selected', true)->count() >= $limit) {
            throw new InvalidArgumentException("You can select up to {$limit} Other items.");
        }
    }

    private function assertOwnedMedia(ProfileIntegration $integration, ProfileIntegrationMedia $media): void
    {
        if (
            (int) $media->profile_integration_id !== (int) $integration->id
            || $media->provider !== ProfileIntegration::PROVIDER_OTHER
        ) {
            throw new InvalidArgumentException('Other integration media was not found.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customLabel(IntegrationDestinationType $destination, array $attributes): ?string
    {
        if ($destination !== IntegrationDestinationType::Other) {
            return null;
        }

        return $this->trimmed((string) ($attributes['custom_destination_label'] ?? ''));
    }

    private function trimmed(string $value): string
    {
        return trim($value);
    }
}
