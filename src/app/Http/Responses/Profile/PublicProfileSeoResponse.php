<?php

namespace App\Http\Responses\Profile;

use App\Models\Profile;
use App\Models\ProfileAvatar;

class PublicProfileSeoResponse
{
    public function __construct(private readonly Profile $profile) {}

    public function toArray(): array
    {
        return [
            'alias' => $this->profile->alias,
            'name' => $this->profile->name,
            'locale' => $this->profile->locale ?: 'es',
            'image_url' => $this->imageUrl(),
            'networks' => (object) $this->publicNetworks(),
            'updated_at' => $this->profile->updated_at?->toAtomString(),
        ];
    }

    private function imageUrl(): ?string
    {
        /** @var ProfileAvatar|null $avatar */
        $avatar = $this->profile->avatars->first();

        return $avatar
            ? (new PublicProfileAvatarResponse($avatar))->imageUrl()
            : null;
    }

    private function isPublicHttpUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        return in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);
    }

    private function publicNetworks(): array
    {
        return collect($this->profile->networks ?? [])
            ->filter(fn ($url, $key): bool => is_string($key)
                && is_string($url)
                && $this->isPublicHttpUrl($url))
            ->map(fn (string $url): string => trim($url))
            ->all();
    }
}
