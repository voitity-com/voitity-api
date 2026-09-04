<?php

namespace App\Http\Responses\Profile;

use App\Models\ProfileAvatar;
use Illuminate\Support\Facades\Storage;

class PublicProfileAvatarResponse
{
    public function __construct(private readonly ProfileAvatar $avatar) {}

    public function toArray(): array
    {
        return [
            'file' => $this->avatar->file,
            'image_url' => $this->imageUrl(),
        ];
    }

    public function imageUrl(): ?string
    {
        foreach ([
            $this->avatar->aiImage?->file,
            $this->avatar->file,
            $this->avatar->original_file,
        ] as $file) {
            if (is_string($file) && $this->isImageFile($file)) {
                return $this->publicUrl($file);
            }
        }

        return null;
    }

    private function publicUrl(string $file): string
    {
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
}
