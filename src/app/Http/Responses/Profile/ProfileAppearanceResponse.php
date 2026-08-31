<?php

namespace App\Http\Responses\Profile;

use App\Models\Profile;
use App\Models\ProfileAppearance;
use Illuminate\Support\Facades\Storage;

class ProfileAppearanceResponse
{
    public function __construct(private readonly ?ProfileAppearance $appearance) {}

    public static function forProfile(Profile $profile): self
    {
        $appearance = $profile->relationLoaded('appearance')
            ? $profile->appearance
            : $profile->appearance()->first();

        return new self($appearance);
    }

    public function toArray(bool $includeStoredImage = true): array
    {
        $backgroundType = $this->appearance?->background_type ?? ProfileAppearance::BACKGROUND_CSS;
        $imageUrl = $this->backgroundImageUrl();

        return [
            'template_key' => $this->appearance?->template_key ?? 'profile01',
            'background_type' => $backgroundType,
            'background_image_url' => $includeStoredImage || $backgroundType === ProfileAppearance::BACKGROUND_IMAGE
                ? $imageUrl
                : null,
            'has_background_image' => $imageUrl !== null,
            'updated_at' => $this->appearance?->updated_at?->toJSON(),
        ];
    }

    private function backgroundImageUrl(): ?string
    {
        $disk = $this->appearance?->background_image_disk;
        $path = $this->appearance?->background_image_path;

        if (! filled($disk) || ! filled($path)) {
            return null;
        }

        return Storage::disk($disk)->url($path);
    }
}
