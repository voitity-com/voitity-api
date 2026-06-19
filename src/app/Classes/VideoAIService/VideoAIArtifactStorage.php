<?php

namespace App\Classes\VideoAIService;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class VideoAIArtifactStorage
{
    public function storeImageFromUrl(string $url, int|string $id): string
    {
        return $this->storeFromUrl($url, $this->imageFolder(), $id, 'png');
    }

    public function storeVideoFromUrl(string $url, int|string $id): string
    {
        return $this->storeFromUrl($url, $this->videoFolder(), $id, 'mp4');
    }

    protected function storeFromUrl(string $url, string $folder, int|string $id, string $defaultExtension): string
    {
        $response = Http::get($url);

        if (!$response->successful()) {
            throw new RuntimeException("Unable to download generated artifact from {$url}");
        }

        $extension = $this->extensionFromUrl($url) ?: $this->extensionFromContentType(
            $response->header('Content-Type'),
            $defaultExtension
        );

        $path = "{$folder}/{$id}.{$extension}";
        $disk = Storage::disk($this->disk());

        if (!$disk->put($path, $response->body())) {
            throw new RuntimeException("Unable to store generated artifact at {$path}");
        }

        return $disk->url($path);
    }

    protected function extensionFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = $path ? pathinfo($path, PATHINFO_EXTENSION) : null;

        return $extension ? strtolower($extension) : null;
    }

    protected function extensionFromContentType(?string $contentType, string $defaultExtension): string
    {
        return match (strtolower((string) $contentType)) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            default => $defaultExtension,
        };
    }

    private function disk(): string
    {
        return (string) config('videoai.profiles.disk', 'profiles');
    }

    private function imageFolder(): string
    {
        return $this->folder('videoai.profiles.image_folder', 'images');
    }

    private function videoFolder(): string
    {
        return $this->folder('videoai.profiles.video_folder', 'videos');
    }

    private function folder(string $key, string $default): string
    {
        $folder = trim((string) config($key, $default), '/');

        return $folder !== '' ? $folder : $default;
    }
}
