<?php

namespace App\Http\Responses\Profile;

use App\Models\Profile;
use App\Models\ProfileAvatar;
use Illuminate\Support\Facades\Storage;

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
        $file = $avatar?->aiImage?->file;

        if (! is_string($file) || ! $this->isImageFile($file)) {
            $file = $avatar?->file;
        }

        if (! is_string($file) || ! $this->isImageFile($file)) {
            return null;
        }

        if ($this->isPublicHttpUrl($file)) {
            return $file;
        }

        return Storage::disk((string) config('videoai.profiles.disk', 'profiles'))->url($file);
    }

    private function isImageFile(string $file): bool
    {
        $path = parse_url($file, PHP_URL_PATH);
        $extension = strtolower(pathinfo(is_string($path) ? $path : $file, PATHINFO_EXTENSION));

        return in_array($extension, ['avif', 'gif', 'jpeg', 'jpg', 'png', 'webp'], true);
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
