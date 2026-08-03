<?php

namespace App\Services\Integrations;

use App\Classes\Subscriptions\SubscriptionPlanCapabilityService;
use App\Classes\YouTubeService\YouTubeChannel;
use App\Classes\YouTubeService\YouTubeClient;
use App\Classes\YouTubeService\YouTubeVideo;
use App\Models\Profile;
use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class YouTubeIntegrationService
{
    public function __construct(
        private readonly YouTubeClient $client,
        private readonly SubscriptionPlanCapabilityService $capabilities,
    ) {}

    public function connect(Profile $profile, User $user, string $channelUrl): ProfileIntegration
    {
        $this->assertUserOwnsProfile($profile, $user);
        $channel = $this->client->getChannel($channelUrl);
        $existing = $profile->integrations()
            ->where('provider', ProfileIntegration::PROVIDER_YOUTUBE)
            ->first();

        if ($existing instanceof ProfileIntegration
            && filled($existing->provider_user_id)
            && $existing->provider_user_id !== $channel->id
            && $existing->media()->exists()) {
            throw new InvalidArgumentException('Delete the current YouTube videos before changing the channel.');
        }

        return ProfileIntegration::query()->updateOrCreate(
            [
                'profile_id' => $profile->id,
                'provider' => ProfileIntegration::PROVIDER_YOUTUBE,
            ],
            [
                'user_id' => $user->id,
                'provider_user_id' => $channel->id,
                'username' => $channel->handle ?: $channel->title,
                'access_token' => null,
                'refresh_token' => null,
                'token_type' => null,
                'scopes' => null,
                'expires_at' => null,
                'refresh_expires_at' => null,
                'last_synced_at' => now(),
                'status' => ProfileIntegration::STATUS_CONNECTED,
                'metadata' => $this->channelMetadata($channel, $channelUrl),
            ],
        );
    }

    public function addVideo(
        ProfileIntegration $integration,
        string $videoUrl,
        string $description,
        bool $selected = true,
    ): ProfileIntegrationMedia {
        $this->assertYouTube($integration);
        $video = $this->client->getVideo($videoUrl);

        if ($video->channelId !== (string) $integration->provider_user_id) {
            throw new InvalidArgumentException('The video does not belong to the connected YouTube channel.');
        }

        if (! $video->isAccessible()) {
            throw new InvalidArgumentException('The YouTube video is private, unavailable, or does not allow embedding.');
        }

        if ($integration->media()->where('provider_media_id', $video->id)->exists()) {
            throw new InvalidArgumentException('This YouTube video has already been added.');
        }

        if ($selected && $integration->media()->where('selected', true)->count() >= $this->selectionLimit($integration)) {
            throw new InvalidArgumentException('The YouTube media selection limit has been reached.');
        }

        return ProfileIntegrationMedia::query()->create([
            'profile_integration_id' => $integration->id,
            'profile_id' => $integration->profile_id,
            'provider' => ProfileIntegration::PROVIDER_YOUTUBE,
            'provider_media_id' => $video->id,
            'media_type' => 'VIDEO',
            'media_url' => $video->url,
            'thumbnail_url' => $video->thumbnailUrl,
            'permalink' => $video->url,
            'caption' => $video->title,
            'observation' => trim($description),
            'age_restricted' => false,
            'selected' => $selected,
            'taken_at' => $video->publishedAt,
            'metadata' => $this->videoMetadata($video),
        ]);
    }

    /**
     * @param  array<int, array{id: int|string, selected?: bool, observation?: null|string}>  $items
     */
    public function updateSelection(ProfileIntegration $integration, array $items): ProfileIntegration
    {
        $this->assertYouTube($integration);
        $itemsById = collect($items)->keyBy(fn (array $item): string => (string) $item['id']);
        $media = $integration->media()->get();
        $unknownIds = $itemsById->keys()->diff($media->pluck('id')->map(fn ($id): string => (string) $id));

        if ($unknownIds->isNotEmpty()) {
            throw new InvalidArgumentException('One or more YouTube media items do not belong to this profile.');
        }

        $selectedCount = $media->sum(function (ProfileIntegrationMedia $item) use ($itemsById): int {
            $input = $itemsById->get((string) $item->id);

            return (int) (is_array($input) ? (bool) ($input['selected'] ?? false) : $item->selected);
        });

        if ($selectedCount > $this->selectionLimit($integration)) {
            throw new InvalidArgumentException('The YouTube media selection limit has been reached.');
        }

        DB::transaction(function () use ($itemsById, $media): void {
            $media->each(function (ProfileIntegrationMedia $item) use ($itemsById): void {
                $input = $itemsById->get((string) $item->id);

                if (! is_array($input)) {
                    return;
                }

                $item->forceFill([
                    'selected' => (bool) ($input['selected'] ?? false),
                    'observation' => trim((string) ($input['observation'] ?? '')),
                ])->save();
            });
        });

        return $integration->fresh(['media']);
    }

    public function deleteMedia(ProfileIntegration $integration, ProfileIntegrationMedia $media): void
    {
        $this->assertYouTube($integration);

        if ((int) $media->profile_integration_id !== (int) $integration->id
            || $media->provider !== ProfileIntegration::PROVIDER_YOUTUBE) {
            throw new InvalidArgumentException('YouTube media was not found.');
        }

        $media->delete();
    }

    public function disconnect(ProfileIntegration $integration): void
    {
        $this->assertYouTube($integration);
        $integration->delete();
    }

    /**
     * Refresh provider metadata that must not remain stale for more than 30 days.
     *
     * @return array{integration: ProfileIntegration, unavailable_media: int, updated_media: int}
     */
    public function refresh(ProfileIntegration $integration): array
    {
        $this->assertYouTube($integration);
        $channel = $this->client->getChannelById((string) $integration->provider_user_id);
        $media = $integration->media()->get();
        $videos = $this->client->getVideosById($media->pluck('provider_media_id')->all());
        $updated = 0;
        $unavailable = 0;

        DB::transaction(function () use ($channel, $integration, $media, $videos, &$updated, &$unavailable): void {
            $integration->forceFill([
                'username' => $channel->handle ?: $channel->title,
                'last_synced_at' => now(),
                'status' => ProfileIntegration::STATUS_CONNECTED,
                'metadata' => [
                    ...($integration->metadata ?? []),
                    ...$this->channelMetadata($channel, (string) data_get($integration->metadata, 'source_url', $channel->url)),
                ],
            ])->save();

            foreach ($media as $item) {
                $video = $videos[(string) $item->provider_media_id] ?? null;

                if (! $video instanceof YouTubeVideo || ! $video->isAccessible() || $video->channelId !== $channel->id) {
                    $item->forceFill([
                        'selected' => false,
                        'thumbnail_url' => null,
                        'caption' => null,
                        'metadata' => [
                            'availability' => 'unavailable',
                            'last_verified_at' => now()->toIso8601String(),
                        ],
                    ])->save();
                    $unavailable++;

                    continue;
                }

                $item->forceFill([
                    'media_url' => $video->url,
                    'thumbnail_url' => $video->thumbnailUrl,
                    'permalink' => $video->url,
                    'caption' => $video->title,
                    'taken_at' => $video->publishedAt,
                    'metadata' => $this->videoMetadata($video),
                ])->save();
                $updated++;
            }
        });

        return [
            'integration' => $integration->fresh(['media']),
            'unavailable_media' => $unavailable,
            'updated_media' => $updated,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function channelMetadata(YouTubeChannel $channel, string $sourceUrl): array
    {
        return [
            'channel_id' => $channel->id,
            'channel_title' => $channel->title,
            'channel_handle' => $channel->handle,
            'channel_url' => $channel->url,
            'channel_thumbnail_url' => $channel->thumbnailUrl,
            'source_url' => trim($sourceUrl),
            'last_verified_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function videoMetadata(YouTubeVideo $video): array
    {
        return [
            'availability' => 'available',
            'channel_id' => $video->channelId,
            'channel_title' => $video->channelTitle,
            'embeddable' => $video->embeddable,
            'last_verified_at' => now()->toIso8601String(),
            'privacy_status' => $video->privacyStatus,
            'video_title' => $video->title,
        ];
    }

    private function selectionLimit(ProfileIntegration $integration): int
    {
        return $this->capabilities->selectedMediaPerProfile(
            $integration->profile,
            ProfileIntegration::PROVIDER_YOUTUBE,
        );
    }

    private function assertYouTube(ProfileIntegration $integration): void
    {
        if ($integration->provider !== ProfileIntegration::PROVIDER_YOUTUBE) {
            throw new InvalidArgumentException('Unsupported integration provider.');
        }
    }

    private function assertUserOwnsProfile(Profile $profile, User $user): void
    {
        if ((int) $profile->user_id !== (int) $user->id) {
            throw new InvalidArgumentException('Profile not found.');
        }
    }
}
