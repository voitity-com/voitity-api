<?php

namespace App\Classes\VideoAIService;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class VideoAIArtifactStorage
{
    public function storeImageFromUrl(string $url, int|string $id): string
    {
        return $this->storeFromUrl($url, 'aiimages', $id, 'png');
    }

    public function storeVideoFromUrl(string $url, int|string $id): string
    {
        return $this->storeFromUrl($url, 'aivideos', $id, 'mp4');
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
        Storage::disk('public')->put($path, $response->body());

        return $path;
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
}
