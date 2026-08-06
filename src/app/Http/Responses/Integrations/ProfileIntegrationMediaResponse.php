<?php

namespace App\Http\Responses\Integrations;

use App\Models\ProfileIntegration;
use App\Models\ProfileIntegrationMedia;
use App\Services\Integrations\IntegrationDestinationCatalog;

class ProfileIntegrationMediaResponse
{
    public function __construct(
        private readonly ProfileIntegrationMedia $media,
        private readonly ?string $locale = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $destination = app(IntegrationDestinationCatalog::class)
            ->labelsForMedia($this->media->metadata, $this->locale);

        return [
            'id' => $this->media->id,
            'provider' => $this->media->provider,
            'provider_media_id' => $this->media->provider_media_id,
            'media_type' => $this->media->media_type,
            'media_url' => $this->media->media_url,
            'thumbnail_url' => $this->media->thumbnail_url,
            'permalink' => $this->media->permalink,
            'caption' => $this->media->caption,
            'observation' => filled($this->media->observation)
                ? $this->media->observation
                : $this->media->caption,
            'age_restricted' => $this->media->age_restricted,
            'selected' => $this->media->selected,
            'taken_at' => $this->media->taken_at?->toIso8601String(),
            'channel_url' => $this->media->provider === ProfileIntegration::PROVIDER_YOUTUBE
                ? data_get($this->media->integration?->metadata, 'channel_url')
                : null,
            'availability' => data_get($this->media->metadata, 'availability', 'available'),
            ...$destination,
        ];
    }
}
