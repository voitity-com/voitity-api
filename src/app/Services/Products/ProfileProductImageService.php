<?php

namespace App\Services\Products;

use App\Models\ProfileProduct;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProfileProductImageService
{
    /**
     * @return array{height: null|int, mime_type: null|string, url: string, width: null|int}
     */
    public function openGraphImage(ProfileProduct $product): array
    {
        $url = $this->imageUrl($product);

        if (filled($product->storage_disk) && filled($product->social_storage_path)) {
            $url = Storage::disk($product->storage_disk)->url($product->social_storage_path);
        }

        return [
            'url' => $url,
            'mime_type' => $product->social_image_mime_type,
            'width' => $product->social_image_width,
            'height' => $product->social_image_height,
        ];
    }

    public function imageUrl(ProfileProduct $product): string
    {
        if (filled($product->storage_disk) && filled($product->storage_path)) {
            return Storage::disk($product->storage_disk)->url($product->storage_path);
        }

        return (string) $product->getRawOriginal('image_url');
    }

    /**
     * @return array{height: null|int, mime_type: null|string, path: null|string, width: null|int}
     */
    public function storeSocialPreview(
        UploadedFile $image,
        string $disk,
        string $directory,
        string $visibility
    ): array {
        $realPath = $image->getRealPath();

        if (! is_string($realPath)) {
            return $this->emptyMetadata();
        }

        $contents = file_get_contents($realPath);

        if ($contents === false) {
            return $this->emptyMetadata();
        }

        return $this->storeSocialPreviewContents($contents, $disk, $directory, $visibility);
    }

    public function refreshSocialPreview(ProfileProduct $product): bool
    {
        if (! filled($product->storage_disk) || ! filled($product->storage_path)) {
            return false;
        }

        $disk = Storage::disk($product->storage_disk);
        $previousPath = $product->social_storage_path;
        $contents = $disk->get($product->storage_path);
        $preview = $this->storeSocialPreviewContents(
            $contents,
            $product->storage_disk,
            dirname($product->storage_path),
            (string) config('products.visibility', 'public')
        );

        $product->forceFill([
            'social_storage_path' => $preview['path'],
            'social_image_mime_type' => $preview['mime_type'],
            'social_image_width' => $preview['width'],
            'social_image_height' => $preview['height'],
        ])->save();

        if (filled($previousPath) && $previousPath !== $preview['path']) {
            $disk->delete($previousPath);
        }

        return filled($preview['path']);
    }

    /**
     * @return array{height: null|int, mime_type: null|string, path: null|string, width: null|int}
     */
    private function storeSocialPreviewContents(
        string $contents,
        string $disk,
        string $directory,
        string $visibility
    ): array {
        $properties = @getimagesizefromstring($contents);

        if (! is_array($properties)) {
            return $this->emptyMetadata();
        }

        $metadata = [
            'path' => null,
            'mime_type' => $properties['mime'] ?? null,
            'width' => isset($properties[0]) ? (int) $properties[0] : null,
            'height' => isset($properties[1]) ? (int) $properties[1] : null,
        ];
        $pixels = (int) ($metadata['width'] ?? 0) * (int) ($metadata['height'] ?? 0);

        if (
            $pixels <= 0
            || $pixels > max(1, (int) config('products.social_image_max_pixels', 20_000_000))
            || ! function_exists('imagecreatefromstring')
            || ! function_exists('imagejpeg')
        ) {
            return $metadata;
        }

        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            return $metadata;
        }

        $width = max(1, (int) config('products.social_image_width', 1200));
        $height = max(1, (int) config('products.social_image_height', 630));
        $canvas = imagecreatetruecolor($width, $height);

        if ($canvas === false) {
            imagedestroy($source);

            return $metadata;
        }

        try {
            $background = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, $background);
            $scale = min($width / imagesx($source), $height / imagesy($source));
            $targetWidth = max(1, (int) round(imagesx($source) * $scale));
            $targetHeight = max(1, (int) round(imagesy($source) * $scale));
            $targetX = (int) floor(($width - $targetWidth) / 2);
            $targetY = (int) floor(($height - $targetHeight) / 2);
            imagecopyresampled(
                $canvas,
                $source,
                $targetX,
                $targetY,
                0,
                0,
                $targetWidth,
                $targetHeight,
                imagesx($source),
                imagesy($source)
            );
            imageinterlace($canvas, true);
            ob_start();
            $written = imagejpeg(
                $canvas,
                null,
                min(100, max(1, (int) config('products.social_image_quality', 88)))
            );
            $jpeg = ob_get_clean();

            if (! $written || ! is_string($jpeg) || $jpeg === '') {
                return $metadata;
            }

            $hash = substr(hash('sha256', $jpeg), 0, 16);
            $path = trim($directory, '/')."/social-{$hash}.jpg";
            $stored = Storage::disk($disk)->put($path, $jpeg, ['visibility' => $visibility]);

            if (! $stored) {
                return $metadata;
            }

            return [
                'path' => $path,
                'mime_type' => 'image/jpeg',
                'width' => $width,
                'height' => $height,
            ];
        } catch (Throwable $exception) {
            Log::warning('Unable to create a product social image preview.', [
                'disk' => $disk,
                'directory' => $directory,
                'exception' => $exception->getMessage(),
            ]);

            return $metadata;
        } finally {
            imagedestroy($canvas);
            imagedestroy($source);
        }
    }

    /**
     * @return array{height: null, mime_type: null, path: null, width: null}
     */
    private function emptyMetadata(): array
    {
        return [
            'path' => null,
            'mime_type' => null,
            'width' => null,
            'height' => null,
        ];
    }
}
