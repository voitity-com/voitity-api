<?php

namespace App\Classes\AvatarImageValidation;

use App\Exceptions\Avatar\AvatarImageValidationUnavailableException;
use App\Exceptions\Avatar\InvalidAvatarSourceImageException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AvatarImageValidator
{
    public function __construct(private readonly AvatarImageValidationClient $client) {}

    /**
     * @throws InvalidAvatarSourceImageException
     * @throws AvatarImageValidationUnavailableException
     */
    public function validate(UploadedFile $image, string $locale = 'es'): AvatarImageValidationResult
    {
        $startedAt = microtime(true);

        try {
            [$bytes, $width, $height] = $this->normalizedImage($image);
            $analysis = $this->client->analyze($bytes);
            $result = $this->evaluate($analysis, $width, $height);

            Log::log($result->valid ? 'info' : 'warning', 'Avatar source image validation '.($result->valid ? 'passed.' : 'failed.'), [
                'provider' => $this->client->name(),
                'request_id' => $result->requestId,
                'reason_codes' => $result->reasonCodes,
                'summary' => $result->summary,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            if (! $result->valid) {
                throw new InvalidAvatarSourceImageException($result, $locale);
            }

            return $result;
        } catch (InvalidAvatarSourceImageException $exception) {
            throw $exception;
        } catch (AvatarImageValidationUnavailableException $exception) {
            Log::error('Avatar source image validation unavailable.', [
                'provider' => $this->client->name(),
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            ]);

            throw $exception;
        }
    }

    private function evaluate(AvatarImageAnalysis $analysis, int $imageWidth, int $imageHeight): AvatarImageValidationResult
    {
        $reasons = [];
        $faceCount = count($analysis->faces);
        $summary = [
            'face_count' => $faceCount,
            'image_width' => $imageWidth,
            'image_height' => $imageHeight,
        ];

        if ($imageWidth < (int) config('avatar-image-validation.thresholds.min_image_width', 512)
            || $imageHeight < (int) config('avatar-image-validation.thresholds.min_image_height', 512)) {
            $reasons[] = 'image_too_small';
        }

        if ($faceCount === 0) {
            $reasons[] = 'no_face';
        } elseif ($faceCount > 1) {
            $reasons[] = 'multiple_faces';
        }

        if ($faceCount === 1) {
            $face = $analysis->faces[0];
            $box = is_array($face['BoundingBox'] ?? null) ? $face['BoundingBox'] : [];
            $width = (float) ($box['Width'] ?? 0);
            $height = (float) ($box['Height'] ?? 0);
            $centerX = (float) ($box['Left'] ?? 0) + ($width / 2);
            $centerY = (float) ($box['Top'] ?? 0) + ($height / 2);
            $area = $width * $height;
            $confidence = (float) ($face['Confidence'] ?? 0);
            $sharpness = (float) ($face['Quality']['Sharpness'] ?? 0);
            $brightness = (float) ($face['Quality']['Brightness'] ?? 0);
            $yaw = abs((float) ($face['Pose']['Yaw'] ?? 0));
            $pitch = abs((float) ($face['Pose']['Pitch'] ?? 0));
            $roll = abs((float) ($face['Pose']['Roll'] ?? 0));

            $summary += [
                'face_confidence' => round($confidence, 2),
                'face_area_ratio' => round($area, 4),
                'face_center_x' => round($centerX, 4),
                'face_center_y' => round($centerY, 4),
                'sharpness' => round($sharpness, 2),
                'brightness' => round($brightness, 2),
                'pose_yaw' => round($yaw, 2),
                'pose_pitch' => round($pitch, 2),
                'pose_roll' => round($roll, 2),
            ];

            if ($confidence < (float) config('avatar-image-validation.thresholds.min_face_confidence', 95)) {
                $reasons[] = 'low_confidence';
            }
            if ($area < (float) config('avatar-image-validation.thresholds.min_face_area_ratio', 0.07)) {
                $reasons[] = 'face_too_small';
            }
            if ($area > (float) config('avatar-image-validation.thresholds.max_face_area_ratio', 0.70)) {
                $reasons[] = 'face_too_large';
            }
            if ($centerX < (float) config('avatar-image-validation.thresholds.min_face_center_x', 0.25)
                || $centerX > (float) config('avatar-image-validation.thresholds.max_face_center_x', 0.75)
                || $centerY < (float) config('avatar-image-validation.thresholds.min_face_center_y', 0.20)
                || $centerY > (float) config('avatar-image-validation.thresholds.max_face_center_y', 0.68)) {
                $reasons[] = 'face_off_center';
            }
            if ($sharpness < (float) config('avatar-image-validation.thresholds.min_sharpness', 35)) {
                $reasons[] = 'low_sharpness';
            }
            if ($brightness < (float) config('avatar-image-validation.thresholds.min_brightness', 25)) {
                $reasons[] = 'poor_lighting';
            }
            if ($yaw > (float) config('avatar-image-validation.thresholds.max_abs_yaw', 30)
                || $pitch > (float) config('avatar-image-validation.thresholds.max_abs_pitch', 25)
                || $roll > (float) config('avatar-image-validation.thresholds.max_abs_roll', 25)) {
                $reasons[] = 'pose_too_large';
            }
            if ($this->confidentBoolean($face, 'FaceOccluded', true)) {
                $reasons[] = 'face_occluded';
            }
            if ($this->confidentBoolean($face, 'EyesOpen', false)) {
                $reasons[] = 'eyes_closed';
            }
            if ($this->confidentBoolean($face, 'Sunglasses', true)) {
                $reasons[] = 'sunglasses';
            }
        }

        $reasons = array_values(array_unique($reasons));

        return new AvatarImageValidationResult(
            valid: $reasons === [],
            reasonCodes: $reasons,
            summary: $summary,
            requestId: $analysis->requestId,
        );
    }

    /**
     * Returns true when an AWS boolean attribute confidently matches the rejected value.
     * For EyesOpen, the rejected value is false; for occlusion and sunglasses it is true.
     *
     * @param  array<string, mixed>  $face
     */
    private function confidentBoolean(array $face, string $key, bool $rejectedValue): bool
    {
        $attribute = is_array($face[$key] ?? null) ? $face[$key] : [];

        return ($attribute['Value'] ?? null) === $rejectedValue
            && (float) ($attribute['Confidence'] ?? 0) >= (float) config('avatar-image-validation.thresholds.boolean_attribute_confidence', 90);
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private function normalizedImage(UploadedFile $image): array
    {
        $path = $image->getRealPath();
        $bytes = is_string($path) ? file_get_contents($path) : false;

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('Avatar source image could not be read.');
        }

        $dimensions = getimagesizefromstring($bytes);
        $resource = imagecreatefromstring($bytes);

        if ($dimensions === false || $resource === false) {
            throw new RuntimeException('Avatar source image could not be decoded.');
        }

        $normalizedResource = $this->resizeForProvider($resource);
        $normalized = $this->encodeJpeg($normalizedResource, (int) config('avatar-image-validation.jpeg_quality', 90));

        if (strlen($normalized) > (int) config('avatar-image-validation.rekognition.max_image_bytes', 5 * 1024 * 1024)) {
            $normalized = $this->encodeJpeg($normalizedResource, 70);
        }

        if ($normalizedResource !== $resource) {
            imagedestroy($normalizedResource);
        }

        imagedestroy($resource);

        if ($normalized === ''
            || strlen($normalized) > (int) config('avatar-image-validation.rekognition.max_image_bytes', 5 * 1024 * 1024)) {
            throw new RuntimeException('Avatar source image could not be normalized.');
        }

        return [$normalized, (int) $dimensions[0], (int) $dimensions[1]];
    }

    private function resizeForProvider(\GdImage $resource): \GdImage
    {
        $width = imagesx($resource);
        $height = imagesy($resource);
        $maxEdge = (int) config('avatar-image-validation.rekognition.max_image_edge', 4096);

        if (max($width, $height) <= $maxEdge) {
            return $resource;
        }

        $scale = $maxEdge / max($width, $height);
        $resized = imagescale(
            $resource,
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
            IMG_BICUBIC,
        );

        if (! $resized instanceof \GdImage) {
            throw new RuntimeException('Avatar source image could not be resized for validation.');
        }

        return $resized;
    }

    private function encodeJpeg(\GdImage $resource, int $quality): string
    {
        ob_start();
        imagejpeg($resource, null, $quality);
        $encoded = ob_get_clean();

        if (! is_string($encoded)) {
            throw new RuntimeException('Avatar source image could not be encoded for validation.');
        }

        return $encoded;
    }
}
